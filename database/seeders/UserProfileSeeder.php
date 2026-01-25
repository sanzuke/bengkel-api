<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserProfileSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Get Main Tenant
        $tenant = Tenant::first();
        if (!$tenant) {
            $this->command->error('No tenant found. Please run DatabaseSeeder first.');
            return;
        }

        // 2. Get Branches (assuming we want to seed for a specific branch, or distribute them)
        // Let's pick the first non-main branch for "Cabang" users, or just the second branch.
        $branch = Branch::where('tenant_id', $tenant->id)
            ->where('is_main', false)
            ->first();

        if (!$branch) {
            // Fallback to any branch if no non-main branch exists
            $branch = Branch::where('tenant_id', $tenant->id)->first();
        }

        if (!$branch) {
            $this->command->error('No branch found.');
            return;
        }

        $this->command->info("Seeding users for Branch: {$branch->name} ({$branch->code})");

        // 3. Ensure Roles Exist
        $roles = ['owner', 'admin', 'kasir', 'teknisi'];
        foreach ($roles as $roleName) {
            if (!Role::where('name', $roleName)->where('guard_name', 'web')->exists()) {
                Role::create(['name' => $roleName, 'guard_name' => 'web']);
                $this->command->info("Created role: {$roleName}");
            }
        }

        // 4. Create Users

        // 4.1. Owner (1 User)
        // Check if owner exists (DatabaseSeeder might have created it)
        $ownerEmail = 'owner@bengkel.com';
        $owner = User::where('email', $ownerEmail)->first();
        
        if (!$owner) {
            $owner = User::create([
                'tenant_id' => $tenant->id,
                'name' => 'Owner Bengkel',
                'email' => $ownerEmail,
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'is_active' => true,
            ]);
            $owner->assignRole('owner');
            
            // Create Employee Profile for Owner (Optional but good for consistency)
            Employee::create([
                'tenant_id' => $tenant->id,
                'branch_id' => null, // Owner is global or main branch
                'user_id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
                'position' => 'Owner',
                'status' => 'active',
                'join_date' => now(),
            ]);
            
            $this->command->info("Created Owner: {$ownerEmail}");
        } else {
            $this->command->info("Owner already exists: {$ownerEmail}");
        }

        // 4.2. Admin Cabang (1 User)
        $this->createUser(
            $tenant,
            $branch,
            'Admin Cabang',
            'admin.cabang@bengkel.com',
            'admin',
            'Kepala Cabang'
        );

        // 4.3. Kasir Cabang (2 Users)
        for ($i = 1; $i <= 2; $i++) {
            $this->createUser(
                $tenant,
                $branch,
                "Kasir Cabang {$i}",
                "kasir.cabang{$i}@bengkel.com",
                'kasir',
                'Kasir'
            );
        }

        // 4.4. Teknisi Cabang (2 Users)
        for ($i = 1; $i <= 2; $i++) {
            $this->createUser(
                $tenant,
                $branch,
                "Teknisi Cabang {$i}",
                "teknisi.cabang{$i}@bengkel.com",
                'teknisi',
                'Mekanik'
            );
        }
    }

    private function createUser($tenant, $branch, $name, $email, $role, $position)
    {
        if (User::where('email', $email)->exists()) {
            $this->command->info("User already exists: {$email}");
            return;
        }

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $user->assignRole($role);

        // Assign User to Branch (Pivot table if exists, otherwise just via Employee)
        // $user->branches()->attach($branch->id); 

        // Create Employee Profile
        Employee::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'name' => $name,
            'email' => $email,
            'position' => $position,
            'status' => 'active',
            'join_date' => now(),
            'nik' => 'EMP-' . strtoupper(uniqid()),
        ]);

        $this->command->info("Created {$role}: {$email} ({$name})");
    }
}
