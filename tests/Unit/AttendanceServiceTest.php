<?php

namespace Tests\Unit;

use App\Models\PresenceEvent;
use App\Models\RecyclingDeposit;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AttendanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AttendanceService::class);
        $this->seedDemo();
    }

    #[Test]
    public function class_attendance_marks_present_before_the_cutoff(): void
    {
        config(['presence.late_cutoff' => '08:15']);

        $maria = $this->cardOf('Maria González');
        PresenceEvent::create([
            'card_id' => $maria->id,
            'reader_id' => $this->reader('classroom')->id,
            'type' => 'CLASS_ATTENDANCE',
            'occurred_at' => now()->setTime(7, 55),
        ]);

        $rows = $this->service->classAttendanceToday(SchoolClass::first()->id);
        $byName = collect($rows)->keyBy(fn ($r) => $r['student']->name);

        $this->assertSame('present', $byName['Maria González']['status']);
        $this->assertSame('07:55', $byName['Maria González']['tappedAt']);
    }

    #[Test]
    public function class_attendance_marks_late_after_the_cutoff(): void
    {
        config(['presence.late_cutoff' => '08:15']);

        $carlos = $this->cardOf('Carlos Pérez');
        PresenceEvent::create([
            'card_id' => $carlos->id,
            'reader_id' => $this->reader('classroom')->id,
            'type' => 'CLASS_ATTENDANCE',
            'occurred_at' => now()->setTime(8, 40),
        ]);

        $rows = $this->service->classAttendanceToday(SchoolClass::first()->id);
        $byName = collect($rows)->keyBy(fn ($r) => $r['student']->name);

        $this->assertSame('late', $byName['Carlos Pérez']['status']);
    }

    #[Test]
    public function students_without_a_tap_today_are_absent(): void
    {
        $rows = $this->service->classAttendanceToday(SchoolClass::first()->id);
        $byName = collect($rows)->keyBy(fn ($r) => $r['student']->name);

        $this->assertSame('absent', $byName['Ana Martínez']['status']);
        $this->assertNull($byName['Ana Martínez']['tappedAt']);
    }

    #[Test]
    public function student_timeline_includes_deposit_details(): void
    {
        $student = Student::where('name', 'Maria González')->firstOrFail();
        $event = PresenceEvent::create([
            'card_id' => $this->cardOf('Maria González')->id,
            'reader_id' => $this->reader('recycling')->id,
            'type' => 'RECYCLING_DEPOSIT',
            'occurred_at' => now(),
        ]);
        RecyclingDeposit::create([
            'event_id' => $event->id,
            'material_class' => 'plastic',
            'confidence' => 0.9,
            'points_awarded' => 10,
        ]);

        $timeline = $this->service->studentTimeline($student);

        $this->assertCount(1, $timeline);
        $this->assertSame('plastic', $timeline[0]['material']);
        $this->assertSame(10, $timeline[0]['points']);
        $this->assertSame('Demo Reader — Recycling', $timeline[0]['reader']);
    }
}
