<?php

namespace App\Services;

use App\Models\Doctor;

class DoctorRatingService
{
    /** Prior rating when doctor has no or few reviews (1-5 scale). */
    protected float $priorRating = 3.5;

    /** Max number of "virtual" prior reviews from completed reservations. */
    protected int $priorWeightCap = 10;

    public function getCompositeRating(Doctor $doctor): float
    {
        $reviewSum = (int) $doctor->reviews()->sum('rating');
        $reviewCount = $doctor->reviews()->count();
        $completedCount = $doctor->reservations()->where('status', 'completed')->count();
        $priorWeight = min($completedCount, $this->priorWeightCap);

        if ($reviewCount + $priorWeight <= 0) {
            return round($this->priorRating, 2);
        }

        $composite = ($reviewSum + $this->priorRating * $priorWeight) / ($reviewCount + $priorWeight);

        return round($composite, 2);
    }

    public function getReviewsCount(Doctor $doctor): int
    {
        return $doctor->reviews()->count();
    }

    public function getCompletedReservationsCount(Doctor $doctor): int
    {
        return $doctor->reservations()->where('status', 'completed')->count();
    }
}
