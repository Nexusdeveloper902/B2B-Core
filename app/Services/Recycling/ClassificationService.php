<?php

namespace App\Services\Recycling;

use App\Contracts\MaterialClassifier;
use App\Enums\MaterialClass;
use App\Models\PresenceEvent;
use App\Models\Reader;
use App\Models\RecyclingDeposit;
use App\Services\PointsService;

/**
 * Classification + points-earn orchestration (Phase C).
 *
 * The MaterialClassifier contract is injected (bound to the configured
 * driver in AppServiceProvider) — tests swap the binding with a fake.
 *
 * Idempotency: one tap = one deposit = one award. A second classify call
 * for the same event_id returns the existing deposit without awarding
 * again (safe for device retries after network failures).
 */
class ClassificationService
{
    public function __construct(
        private readonly MaterialClassifier $classifier,
        private readonly PointsService $points,
    ) {}

    /**
     * @return array{
     *   duplicate: bool,
     *   deposit: RecyclingDeposit,
     *   new_balance: int
     * }
     */
    public function classifyAndAward(Reader $reader, PresenceEvent $event, string $imagePath): array
    {
        $existing = RecyclingDeposit::where('event_id', $event->id)->first();

        if ($existing !== null) {
            $student = $event->card->student;

            return [
                'duplicate' => true,
                'deposit' => $existing,
                'new_balance' => $this->points->balance($student),
            ];
        }

        $result = $this->classifier->classify($imagePath);
        $material = MaterialClass::from($result['material_class']);

        $student = $event->card->student;

        $deposit = RecyclingDeposit::create([
            'event_id' => $event->id,
            'material_class' => $material->value,
            'confidence' => (float) $result['confidence'],
            'points_awarded' => $material->points(),
        ]);

        $this->points->awardRecyclingPoints($student, $event, $material);

        return [
            'duplicate' => false,
            'deposit' => $deposit->refresh(),
            'new_balance' => $this->points->balance($student),
        ];
    }
}
