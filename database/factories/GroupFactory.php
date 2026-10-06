<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Group;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Group> */
final class GroupFactory extends Factory
{
    protected $model = Group::class;

    /**
     * Only descriptive fields are generated here.
     *
     * owner_id and member_ids must point at real user documents, so the seeder
     * supplies them: in a document database a factory cannot invent valid
     * references, it can only invent values.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->words(2, true),
            'description' => $this->faker->sentence(6),
        ];
    }
}