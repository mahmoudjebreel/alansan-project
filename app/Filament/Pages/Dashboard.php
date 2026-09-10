<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

/**
 * The panel's home page.
 *
 * Filament's stock dashboard is a two-column grid with the account card in
 * the first cell, which put the greeting above the numbers and, with an odd
 * number of one-column charts, left an empty cell in the middle of the page.
 * This page widens the grid to three columns on large screens and lets each
 * widget say how much of it to take, so every row is filled: the figures
 * first, the trend line across the full width, then the charts.
 */
class Dashboard extends BaseDashboard
{
    /**
     * @return int | array<string, ?int>
     */
    public function getColumns(): int | array
    {
        return [
            'default' => 1,
            'md' => 2,
            'xl' => 3,
        ];
    }
}
