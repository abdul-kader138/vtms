<?php

namespace App\Services\Account;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AccountDataService
{
    public function export(User $user): array
    {
        return [
            'profile' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'locale' => $user->locale,
                'created_at' => $user->created_at,
            ],
        ];
    }

    public function anonymize(User $user): void
    {
        $previousAvatar = $user->avatar;

        DB::transaction(function () use ($user): void {
            $user->forceFill([
                'first_name' => 'Deleted',
                'last_name' => 'User',
                'email' => "deleted-user-{$user->id}@example.invalid",
                'phone' => null,
                'google_id' => null,
                'avatar' => null,
                'password' => Hash::make(Str::random(40)),
            ])->save();

            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('user_sessions')->where('user_id', $user->id)->delete();
            $user->tokens()->delete();
        });

        if ($previousAvatar) {
            Storage::disk('public')->delete($previousAvatar);
        }
    }
}
