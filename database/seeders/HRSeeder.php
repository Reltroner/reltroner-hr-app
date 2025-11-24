<?php
// database/seeders/HRSeeder.php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Faker\Factory as Faker;
use Carbon\Carbon;
use App\Models\Presence;
use App\Models\Employee;

class HRSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create();

        /** -------------------------------
         *  Seed Departments
         *--------------------------------*/
        DB::table('departments')->insert([
            ['id' => 1, 'name' => 'Engineering', 'description' => 'Handles all technical tasks.', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'HR',          'description' => 'Manages employee welfare.',     'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'Finance',     'description' => 'Handles financial matters.',     'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);

        /** -------------------------------
         *  Seed Roles
         *--------------------------------*/
        DB::table('roles')->insert([
            ['id' => 1, 'title' => 'Admin',      'description' => 'Administrator with full access.', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'title' => 'HR Manager', 'description' => 'Manages HR policies.',            'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'title' => 'Animator',   'description' => 'Creates visual animations.',      'created_at' => now(), 'updated_at' => now()],
            ['id' => 4, 'title' => 'Data Entry', 'description' => 'Inputs and manages data.',        'created_at' => now(), 'updated_at' => now()],
            ['id' => 5, 'title' => 'Accountant', 'description' => 'Handles accounting tasks.',       'created_at' => now(), 'updated_at' => now()],
            ['id' => 6, 'title' => 'Marketer',   'description' => 'Executes marketing plans.',       'created_at' => now(), 'updated_at' => now()],
            ['id' => 7, 'title' => 'Developer',  'description' => 'Builds and maintains software.',  'created_at' => now(), 'updated_at' => now()],
        ]);

        /** -------------------------------
         *  Seed Employees
         *--------------------------------*/
        for ($i = 1; $i <= 10; $i++) {
            DB::table('employees')->insert([
                'id'            => $i,
                'fullname'      => $faker->name(),
                'email'         => $faker->unique()->safeEmail(),
                'phone'         => $faker->phoneNumber(),
                'address'       => $faker->address(),
                'birth_date'    => $faker->dateTimeBetween('-45 years', '-22 years'),
                'hire_date'     => $faker->dateTimeBetween('-5 years', 'now'),
                'department_id' => rand(1, 3),
                'role_id'       => rand(1, 7),
                'status'        => 'active',
                'salary'        => $faker->randomFloat(2, 3000, 10000),
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        /** -------------------------------
         *  Seed Tasks
         *--------------------------------*/
        for ($i = 0; $i < 20; $i++) {
            DB::table('tasks')->insert([
                'title'       => $faker->bs(),
                'description' => $faker->paragraph(),
                'assigned_to' => rand(1, 10),
                'due_date'    => $faker->dateTimeBetween('now', '+30 days'),
                'status'      => $faker->randomElement(['pending', 'in_progress', 'completed']),
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        /** -------------------------------
         *  Seed Payrolls
         *--------------------------------*/
        foreach (Employee::all() as $employee) {
            $salary      = $employee->salary;
            $bonus       = $faker->randomFloat(2, 0, 0.2 * $salary);
            $deduction   = $faker->randomFloat(2, 0, 0.1 * $salary);
            $netSalary   = round($salary + $bonus - $deduction, 2);

            DB::table('payrolls')->insert([
                'employee_id'  => $employee->id,
                'salary'       => $salary,
                'bonus'        => $bonus,
                'deduction'    => $deduction,
                'net_salary'   => $netSalary,
                'payment_date' => $faker->dateTimeThisYear(),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }

        /** -------------------------------
         *  Seed Presences (fixed duplicate issue)
         *--------------------------------*/
        $employees = Employee::pluck('id');

        for ($i = 0; $i < 100; $i++) {
            $employeeId = $employees->random();
            $date       = Carbon::create(now()->year, rand(1, 12), rand(1, 28))->toDateString();

            $checkIn  = Carbon::parse("$date " . rand(8, 10) . ":" . rand(0, 59));
            $checkOut = (clone $checkIn)->addHours(8);

            Presence::firstOrCreate(
                ['employee_id' => $employeeId, 'date' => $date], // unique key check
                [
                    'check_in'   => $checkIn,
                    'check_out'  => $checkOut,
                    'status'     => $faker->randomElement(['present', 'absent', 'late', 'leave']),
                    'latitude'   => rand(1, 100) <= 80 ? -6.175 + (mt_rand(-1000, 1000) / 10000) : null,
                    'longitude'  => rand(1, 100) <= 80 ? 106.827 + (mt_rand(-1000, 1000) / 10000) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        /** -------------------------------
         *  Seed Leave Requests
         *--------------------------------*/
        for ($i = 0; $i < 10; $i++) {
            $start = $faker->dateTimeBetween('-60 days', '-1 days');
            DB::table('leave_requests')->insert([
                'employee_id' => rand(1, 10),
                'leave_type'  => $faker->randomElement(['sick', 'vacation', 'personal']),
                'start_date'  => $start,
                'end_date'    => Carbon::parse($start)->addDays(rand(1, 5)),
                'status'      => $faker->randomElement(['approved', 'pending', 'rejected']),
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }
}
