<?php

namespace Tests\Unit;

use App\Models\PresenceEvent;
use App\Models\RecyclingDeposit;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\NlQuery\FunctionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every NL-query callable function must return REAL database numbers —
 * independently testable with zero LLM involvement (protocol Phase E).
 */
class FunctionRegistryTest extends TestCase
{
    use RefreshDatabase;

    private FunctionRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = app(FunctionRegistry::class);
        $this->seedDemo();
        $this->generateEvents();
    }

    #[Test]
    public function declares_exactly_the_fixed_function_set(): void
    {
        $names = array_column($this->registry->declarations(), 'name');

        $this->assertSame(
            ['get_attendance_count', 'get_pae_count', 'get_recycling_totals', 'get_student_timeline'],
            $names
        );
    }

    #[Test]
    public function every_declaration_has_the_required_schema_fields(): void
    {
        foreach ($this->registry->declarations() as $declaration) {
            $this->assertNotEmpty($declaration['description']);
            $this->assertArrayHasKey('parameters', $declaration);
            $this->assertNotEmpty($declaration['parameters']['properties']);
        }
    }

    #[Test]
    public function attendance_count_counts_distinct_students(): void
    {
        // Maria + Carlos tapped in; Maria tapped twice (dedup expected).
        $today = now()->toDateString();

        $result = $this->registry->execute('get_attendance_count', ['date' => $today]);

        $this->assertSame(2, $result['attendance_count']);
    }

    #[Test]
    public function attendance_count_scopes_to_class(): void
    {
        $classId = SchoolClass::first()->id;
        $today = now()->toDateString();

        $result = $this->registry->execute('get_attendance_count', ['date' => $today, 'class_id' => $classId]);

        $this->assertSame(2, $result['attendance_count']);

        $otherClass = SchoolClass::create(['name' => 'Empty class']);
        $this->assertSame(
            0,
            $this->registry->execute('get_attendance_count', ['date' => $today, 'class_id' => $otherClass->id])['attendance_count']
        );
    }

    #[Test]
    public function pae_count_separates_meals(): void
    {
        $today = now()->toDateString();

        $this->assertSame(
            2,
            $this->registry->execute('get_pae_count', ['meal' => 'breakfast', 'date' => $today])['pae_count']
        );
        $this->assertSame(
            1,
            $this->registry->execute('get_pae_count', ['meal' => 'lunch', 'date' => $today])['pae_count']
        );
    }

    #[Test]
    public function recycling_totals_aggregate_items_and_points(): void
    {
        $from = now()->toDateString();
        $to = now()->toDateString();

        $result = $this->registry->execute('get_recycling_totals', ['date_from' => $from, 'date_to' => $to]);

        $this->assertSame(2, $result['items']);
        $this->assertSame(25, $result['points']); // plastic(10) + metal(15)
        $this->assertSame(1, $result['by_material']['plastic']);
        $this->assertSame(1, $result['by_material']['metal']);
        $this->assertSame(0, $result['by_material']['glass']);
    }

    #[Test]
    public function student_timeline_returns_chronological_events(): void
    {
        $student = Student::where('name', 'Maria González')->firstOrFail();

        $result = $this->registry->execute('get_student_timeline', ['student_id' => $student->id]);

        $this->assertSame($student->name, $result['student_name']);
        $this->assertCount(5, $result['timeline']); // attendance x2 (duplicate tap), pae breakfast, pae lunch, recycling

        $times = array_column($result['timeline'], 'occurred_at');
        $sorted = $times;
        sort($sorted);
        $this->assertSame($sorted, $times, 'Timeline must be chronological.');
    }

    #[Test]
    public function unknown_student_timeline_returns_a_clean_error(): void
    {
        $result = $this->registry->execute('get_student_timeline', ['student_id' => 99999]);

        $this->assertArrayHasKey('error', $result);
    }

    #[Test]
    public function unknown_function_returns_a_clean_error(): void
    {
        $result = $this->registry->execute('does_not_exist', []);

        $this->assertSame('Unknown function [does_not_exist]', $result['error']);
    }

    /**
     * Fixture: a coherent day of events across all three applications.
     */
    private function generateEvents(): void
    {
        $classroom = $this->reader('classroom');
        $recycling = $this->reader('recycling');

        $maria = $this->cardOf('Maria González');
        $carlos = $this->cardOf('Carlos Pérez');

        $tap = function ($card, $reader, $type, $minutesAgo) {
            PresenceEvent::create([
                'card_id' => $card->id,
                'reader_id' => $reader->id,
                'type' => $type,
                'occurred_at' => now()->subMinutes($minutesAgo),
            ]);
        };

        $tap($maria, $classroom, 'CLASS_ATTENDANCE', 200);
        $tap($maria, $classroom, 'CLASS_ATTENDANCE', 199); // duplicate tap — must dedup
        $tap($carlos, $classroom, 'CLASS_ATTENDANCE', 180);
        $tap($maria, $classroom, 'PAE_BREAKFAST', 150);
        $tap($carlos, $classroom, 'PAE_BREAKFAST', 149);
        $tap($maria, $classroom, 'PAE_LUNCH', 60);
        $tap($maria, $recycling, 'RECYCLING_DEPOSIT', 30);

        // Classified deposits: plastic for Maria (+10), metal (+15).
        $recyclingEvent = PresenceEvent::where('type', 'RECYCLING_DEPOSIT')->first();
        RecyclingDeposit::create([
            'event_id' => $recyclingEvent->id,
            'material_class' => 'plastic',
            'confidence' => 0.91,
            'points_awarded' => 10,
        ]);
        $metalEvent = $tap2 = PresenceEvent::create([
            'card_id' => $carlos->id,
            'reader_id' => $recycling->id,
            'type' => 'RECYCLING_DEPOSIT',
            'occurred_at' => now()->subMinutes(20),
        ]);
        RecyclingDeposit::create([
            'event_id' => $metalEvent->id,
            'material_class' => 'metal',
            'confidence' => 0.88,
            'points_awarded' => 15,
        ]);
    }
}
