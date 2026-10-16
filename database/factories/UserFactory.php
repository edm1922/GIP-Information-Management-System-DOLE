<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->optional()->firstName(),
            'last_name' => fake()->lastName(),
            'suffix' => fake()->optional()->suffix(),
            'username' => fake()->unique()->userName(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'must_change_password' => false,
            'role' => 'intern',
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the user has the admin role.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'admin',
        ]);
    }

    /**
     * Indicate that the user has the peso manager role.
     */
    public function pesoManager(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'peso_manager',
        ]);
    }

    /**
     * Indicate that the user has the intern role.
     */
    public function intern(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'intern',
        ]);
    }

    /**
     * Indicate that the user has the encoder role.
     */
    public function encoder(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'encoder',
        ]);
    }
}
