<?php

namespace Tests\Unit\Realtime;

use App\Models\PresenceEvent;
use App\Services\Realtime\RealtimeFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * RealtimeFeed row shaping (TASK-016, ADR-026) — the payload the
 * dashboards render and the WebSocket server pushes.
 */
class RealtimeFeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    #[Test]
    public function recent_events_carry_the_readable_context_oldest_first(): void
    {
        PresenceEvent::create([
            'card_id' => $this->cardOf('Maria González')->id,
            'reader_id' => $this->reader('classroom')->id,
            'type' => 'CLASS_ATTENDANCE',
            'occurred_at' => now()->setTime(7, 50),
        ]);

        $feed = new RealtimeFeed;
        $rows = $feed->recent(20);

        $this->assertNotEmpty($rows);
        // Oldest first (prepend-friendly, SSR-friendly).
        $ids = array_column($rows, 'id');
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids);

        $row = $rows[array_key_last($rows)];
        $this->assertSame('CLASS_ATTENDANCE', $row['type']);
        $this->assertSame('07:50', $row['time']);
        $this->assertSame(now()->toDateString(), $row['date']);
        $this->assertSame('Maria González', $row['student_name']);
        $this->assertSame('Demo Reader — Classroom/PAE', $row['reader_label']);
        $this->assertIsInt($row['student_id']);
        $this->assertNotSame('', (string) $row['class_name']);
    }

    #[Test]
    public function events_after_return_only_newer_rows_oldest_first(): void
    {
        $event = PresenceEvent::create([
            'card_id' => $this->cardOf('Maria González')->id,
            'reader_id' => $this->reader('classroom')->id,
            'type' => 'CLASS_ATTENDANCE',
            'occurred_at' => now()->setTime(7, 50),
        ]);

        $feed = new RealtimeFeed;

        $this->assertSame([], $feed->eventsAfter($event->id));

        PresenceEvent::create([
            'card_id' => $this->cardOf('Carlos Pérez')->id,
            'reader_id' => $this->reader('classroom')->id,
            'type' => 'PAE_BREAKFAST',
            'occurred_at' => now()->setTime(8, 5),
        ]);

        $delta = $feed->eventsAfter($event->id);
        $this->assertCount(1, $delta);
        $this->assertSame('PAE_BREAKFAST', $delta[0]['type']);
        $this->assertSame('Carlos Pérez', $delta[0]['student_name']);
    }

    #[Test]
    public function latest_event_id_tracks_the_head(): void
    {
        $feed = new RealtimeFeed;
        $before = $feed->latestEventId();

        $event = PresenceEvent::create([
            'card_id' => $this->cardOf('Maria González')->id,
            'reader_id' => $this->reader('classroom')->id,
            'type' => 'CLASS_ATTENDANCE',
            'occurred_at' => now(),
        ]);

        $this->assertSame($event->id, $feed->latestEventId());
        $this->assertGreaterThanOrEqual($before, $event->id);
    }
}
