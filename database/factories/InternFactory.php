<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Intern;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Intern>
 */
class InternFactory extends Factory
{
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-6 months', 'now');
        $end = (clone $start)->modify('+3 months');

        return [
            'user_id' => null,
            'partner_agency_id' => function () {
                return Company::inRandomOrder()->first()->id ?? null;
            },
            'contract_start_date' => $start,
            'contract_end_date' => $end,
            'required_hours' => 486,
            'rendered_hours' => fake()->numberBetween(0, 486),
            'status' => 'active',
            'intern_type' => fake()->randomElement(['GIP', 'OJT', 'SPES', 'Work Immersion']),
            'batch' => fake()->year(),
            'school' => fake()->company(),
            'course' => fake()->randomElement(['BSIT', 'BSCS', 'BSA', 'BSED', 'BSBA']),
            'contact_number' => fake()->phoneNumber(),
            'supervisor' => fake()->name(),
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'rendered_hours' => $attributes['required_hours'] ?? 486,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }
}
