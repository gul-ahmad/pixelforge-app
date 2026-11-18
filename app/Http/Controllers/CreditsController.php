<?php

namespace App\Http\Controllers;

use App\Actions\Credits\PurchaseCredits;
use App\Http\Requests\Credits\PurchaseCreditsRequest;
use App\Http\Resources\CreditTransactionResource;
use App\Support\Pricing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/** The user's balance, the fake "Buy credits" packs, and their ledger history. */
class CreditsController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('credits/Index', [
            'balance' => Pricing::formatMicroUsd($user->balance_micro_usd),
            'packs' => array_map(fn (int $micro) => [
                'micro_usd' => $micro,
                'label' => Pricing::formatMicroUsd($micro),
            ], PurchaseCredits::PACKS_MICRO_USD),
            'clientRequestId' => (string) Str::uuid(),
            'transactions' => CreditTransactionResource::collection(
                $user->creditTransactions()->with('generation:id,prompt')->latest('id')->paginate(25)
            ),
        ]);
    }

    public function store(PurchaseCreditsRequest $request, PurchaseCredits $purchase): RedirectResponse
    {
        $transaction = $purchase->handle(
            $request->user(),
            $request->integer('pack_micro_usd'),
            $request->string('client_request_id')->toString(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Added '.Pricing::formatMicroUsd($transaction->amount_micro_usd).' in credits (demo purchase, no payment taken).',
        ]);

        return to_route('credits.index');
    }
}
