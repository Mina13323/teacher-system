<?php

namespace Database\Seeders;

use App\Enums\CourseStatus;
use App\Enums\ExamStatus;
use App\Enums\UserRole;
use App\Models\Competition;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\Lesson;
use App\Models\Option;
use App\Models\Question;
use App\Models\Unit;
use App\Models\User;
use App\Models\Video;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoContentSeeder extends Seeder
{
    public function run(): void
    {
        $teacherEmail = strtolower((string) (env('TEACHER_ACCOUNT_EMAIL') ?: TeacherAccountSeeder::DEFAULT_EMAIL));
        $teacher = User::where('email', $teacherEmail)->first();
        if (! $teacher) {
            return;
        }

        // Avoid duplicate seeding if demo content already exists
        if (Course::where('created_by', $teacher->id)->exists()) {
            return;
        }

        $studentPassword = (string) (env('DEMO_STUDENT_PASSWORD') ?: 'password123');

        // Create sample students
        $students = [];
        $studentData = [
            ['name' => 'Ahmed Hassan', 'email' => 'ahmed.hassan@student.com'],
            ['name' => 'Sara Ali', 'email' => 'sara.ali@student.com'],
            ['name' => 'Omar Mahmoud', 'email' => 'omar.mahmoud@student.com'],
            ['name' => 'Youssef Ibrahim', 'email' => 'youssef.ibrahim@student.com'],
            ['name' => 'Nour El Din', 'email' => 'nour.eldin@student.com'],
        ];

        foreach ($studentData as $data) {
            $student = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => Hash::make($studentPassword),
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );
            $student->syncRoles([UserRole::Student->value]);
            $students[] = $student;
        }

        // Create Demo Course 1: Physical Geography & Cartography
        $course1 = Course::create([
            'title' => 'Physical Geography & Cartography Masterclass',
            'slug' => Str::slug('Physical Geography & Cartography Masterclass'),
            'description' => 'Comprehensive masterclass covering topographic contours, climate dynamics, plate tectonics, and map analysis.',
            'status' => CourseStatus::Published,
            'created_by' => $teacher->id,
        ]);

        $unit1 = Unit::create([
            'course_id' => $course1->id,
            'title' => 'Unit 1: Cartography & Spatial Map Reading',
            'position' => 1,
        ]);

        $lesson1 = Lesson::create([
            'unit_id' => $unit1->id,
            'title' => 'Lesson 1: Map Projections & Coordinate Systems',
            'slug' => Str::slug('Lesson 1 Map Projections Coordinate Systems'),
            'position' => 1,
            'is_published' => true,
            'content' => 'In this lesson, we analyze latitude, longitude, Mercator projections, and GIS spatial data.',
        ]);

        Video::create([
            'lesson_id' => $lesson1->id,
            'title' => 'Cartography & Projections Video Lecture',
            'storage_path' => 'videos/cartography.mp4',
            'duration' => 1200,
            'is_published' => true,
        ]);

        $lesson2 = Lesson::create([
            'unit_id' => $unit1->id,
            'title' => 'Lesson 2: Topographic Maps & Contour Lines',
            'slug' => Str::slug('Lesson 2 Topographic Maps Contour Lines'),
            'position' => 2,
            'is_published' => true,
            'content' => 'Learn how to read elevation profiles, steepness gradients, and landform contours.',
        ]);

        Video::create([
            'lesson_id' => $lesson2->id,
            'title' => 'Topographic Map Walkthrough',
            'storage_path' => 'videos/topography.mp4',
            'duration' => 950,
            'is_published' => true,
        ]);

        // Create Demo Course 2: World History & Ancient Civilizations
        $course2 = Course::create([
            'title' => 'World History & Ancient Civilizations',
            'slug' => Str::slug('World History & Ancient Civilizations'),
            'description' => 'Explore the rise of river valley empires, legal codes, trade routes, and early statecraft.',
            'status' => CourseStatus::Published,
            'created_by' => $teacher->id,
        ]);

        $unit2 = Unit::create([
            'course_id' => $course2->id,
            'title' => 'Unit 1: Nile Valley & Ancient Near East',
            'position' => 1,
        ]);

        Lesson::create([
            'unit_id' => $unit2->id,
            'title' => 'Lesson 1: Agriculture, Irrigation & Early States',
            'slug' => Str::slug('Lesson 1 Agriculture Irrigation Early States'),
            'position' => 1,
            'is_published' => true,
            'content' => 'Understanding how seasonal inundation and river systems enabled early urban settlement.',
        ]);

        // Create Demo Course 3: Modern Geopolitics & Regional Analysis (Draft)
        Course::create([
            'title' => 'Modern Geopolitics & Regional Analysis',
            'slug' => Str::slug('Modern Geopolitics & Regional Analysis'),
            'description' => 'Geopolitical power dynamics, trade corridors, and territorial boundaries.',
            'status' => CourseStatus::Draft,
            'created_by' => $teacher->id,
        ]);

        // Enroll students in courses
        foreach ($students as $index => $student) {
            Enrollment::create([
                'student_id' => $student->id,
                'course_id' => $course1->id,
                'enrolled_at' => now()->subDays($index * 2),
            ]);

            if ($index % 2 === 0) {
                Enrollment::create([
                    'student_id' => $student->id,
                    'course_id' => $course2->id,
                    'enrolled_at' => now()->subDays($index),
                ]);
            }
        }

        // Create an Exam for Course 1
        $exam = Exam::create([
            'course_id' => $course1->id,
            'title' => 'Geography & Spatial Analysis Exam',
            'description' => '30-minute exam evaluating cartography, map reading, and physical systems mastery.',
            'duration_minutes' => 30,
            'pass_percentage' => 60.00,
            'status' => ExamStatus::Published,
            'created_by' => $teacher->id,
        ]);

        $q1 = Question::create([
            'exam_id' => $exam->id,
            'question_text' => 'Which line of latitude separates the Northern and Southern Hemispheres at 0°?',
            'type' => 'single_choice',
            'points' => 10,
            'position' => 1,
        ]);

        Option::create(['question_id' => $q1->id, 'option_text' => 'Equator (0°)', 'is_correct' => true, 'position' => 1]);
        Option::create(['question_id' => $q1->id, 'option_text' => 'Prime Meridian', 'is_correct' => false, 'position' => 2]);
        Option::create(['question_id' => $q1->id, 'option_text' => 'Tropic of Cancer', 'is_correct' => false, 'position' => 3]);

        // Create a Competition
        Competition::create([
            'exam_id' => $exam->id,
            'title' => 'National High School Geography Championship 2026',
            'description' => 'Speed and spatial analysis competition for top geography students.',
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(5),
            'status' => 'published',
            'created_by' => $teacher->id,
        ]);
    }
}
