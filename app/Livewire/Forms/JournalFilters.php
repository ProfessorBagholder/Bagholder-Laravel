<?php

namespace App\Livewire\Forms;

use Flux\DateRange;
use Flux\DateRangePreset;
use Livewire\Attributes\Validate;
use Livewire\Form;

class JournalFilters extends Form
{
    public string $account = '';

    public string $exchange = '';

    public string $symbol = '';

    public string $closedIn = '';

    #[Validate('nullable|date')]
    public string $from = '';

    #[Validate('nullable|date')]
    public string $to = '';

    public ?DateRange $closed = null;

    public string $priceOp = '';

    public string $priceMin = '';

    public string $priceMax = '';

    public string $holdOp = '';

    public string $holdMin = '';

    public string $pnlOp = '';

    public string $pnlMin = '';

    public string $qtyOp = '';

    public string $qtyMin = '';

    public string $symbolQuery = '';

    public string $grade = '';

    public string $tag = '';

    public string $side = '';

    public string $kind = '';

    public string $result = '';

    /**
     * @return array<string, string>
     */
    public function payload(): array
    {
        return [
            'account' => $this->account,
            'exchange' => $this->exchange,
            'symbol' => $this->symbol,
            'from' => $this->from,
            'to' => $this->to,
            'priceOp' => $this->priceOp,
            'priceMin' => $this->priceMin,
            'priceMax' => $this->priceMax,
            'holdOp' => $this->holdOp,
            'holdMin' => $this->holdMin,
            'pnlOp' => $this->pnlOp,
            'pnlMin' => $this->pnlMin,
            'qtyOp' => $this->qtyOp,
            'qtyMin' => $this->qtyMin,
            'grade' => $this->grade,
            'tag' => $this->tag,
            'side' => $this->side,
            'kind' => $this->kind,
            'result' => $this->result,
        ];
    }

    public function active(): bool
    {
        return $this->closedIn !== ''
            || $this->from !== ''
            || $this->to !== ''
            || $this->symbol !== ''
            || $this->account !== ''
            || $this->exchange !== ''
            || $this->priceOp !== ''
            || $this->holdOp !== ''
            || $this->pnlOp !== ''
            || $this->qtyOp !== ''
            || $this->grade !== ''
            || $this->tag !== ''
            || $this->side !== ''
            || $this->kind !== ''
            || $this->result !== '';
    }

    public function applyClosedIn(string $year): void
    {
        $this->closedIn = $year;
        if ($year === '') {
            $this->from = '';
            $this->to = '';
            $this->closed = null;

            return;
        }
        $this->from = $year.'-01-01';
        $this->to = $year.'-12-31';
        $this->closed = new DateRange($this->from, $this->to);
    }

    public function syncClosedInFromRange(): void
    {
        if (preg_match('/^(\d{4})-01-01$/', $this->from, $a)
            && preg_match('/^(\d{4})-12-31$/', $this->to, $b)
            && $a[1] === $b[1]) {
            $this->closedIn = $a[1];
        } else {
            $this->closedIn = '';
        }
    }

    public function syncFromClosedRange(): void
    {
        if ($this->closed === null) {
            $this->from = '';
            $this->to = '';
            $this->closedIn = '';

            return;
        }

        if ($this->closed->preset() === DateRangePreset::AllTime) {
            $this->from = '';
            $this->to = '';
            $this->closedIn = '';

            return;
        }

        $this->from = $this->closed->start()?->toDateString() ?? '';
        $this->to = $this->closed->end()?->toDateString() ?? '';
        $this->syncClosedInFromRange();
    }
}
