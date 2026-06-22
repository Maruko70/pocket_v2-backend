<?php

namespace Database\Factories;

use App\Models\Complex;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Complex>
 */
class ComplexFactory extends Factory
{
    protected $model = Complex::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'bio' => fake()->optional()->paragraph(),
            'logo' => null,
            'phone' => fake()->optional()->phoneNumber(),
            'address' => fake()->optional()->address(),
            'booking_method' => 'slots',
        ];
    }

    public function ticketBooking(): static
    {
        return $this->state(fn (array $attributes) => [
            'booking_method' => 'tickets',
        ]);
    }
}
