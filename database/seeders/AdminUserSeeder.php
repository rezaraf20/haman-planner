<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

final class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) env('PLANNER_ADMIN_EMAIL', 'admin@hamantech.ir');
        $password = (string) env('PLANNER_ADMIN_PASSWORD', config('services.haman_planner.api_token', ''));

        if ($password === '') {
            throw new RuntimeException('PLANNER_ADMIN_PASSWORD or Haman Planner API token must be configured before seeding the admin user.');
        }

        $user = User::firstOrNew(['email' => $email]);
        $user->name = (string) env('PLANNER_ADMIN_NAME', 'Reza');
        if (! $user->exists) {
            $user->password = Hash::make($password);
        }
        $user->save();
    }
}
