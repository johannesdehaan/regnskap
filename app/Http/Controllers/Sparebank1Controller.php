<?php

namespace App\Http\Controllers;

use App\Actions\RefreshSparebank1Accounts;
use App\Exceptions\Sparebank1Exception;
use App\Services\Sparebank1\Sparebank1Client;
use App\Support\Sparebank1Banks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class Sparebank1Controller extends Controller
{
    public function connect(Request $request, Sparebank1Client $client): RedirectResponse
    {
        $finInst = $request->string('fin_inst')->toString();

        if (! Sparebank1Banks::allows($finInst)) {
            return redirect()
                ->route('settings.bank')
                ->with('bank_error', 'Ukjent SpareBank 1-bank. Velg en bank fra listen, eller la banken spørre.');
        }

        try {
            $state = Str::random(40);
            $request->session()->put('sparebank1.state', $state);
            $request->session()->put('sparebank1.fin_inst', $finInst);

            return redirect()->away($client->authorizeUrl($state, $finInst));
        } catch (Sparebank1Exception $exception) {
            return redirect()
                ->route('settings.bank')
                ->with('bank_error', $exception->getMessage());
        }
    }

    public function callback(Request $request, Sparebank1Client $client, RefreshSparebank1Accounts $accounts): RedirectResponse
    {
        $error = $request->string('error')->toString();

        if ($error !== '') {
            $request->session()->forget(['sparebank1.state', 'sparebank1.fin_inst']);

            return redirect()
                ->route('settings.bank')
                ->with('bank_error', $error === 'access_denied'
                    ? 'BankID-påloggingen ble avbrutt eller avslått.'
                    : 'SpareBank 1 avslo innloggingen.');
        }

        $expected = $request->session()->pull('sparebank1.state');
        $finInst = $request->session()->pull('sparebank1.fin_inst');
        $given = $request->query('state');

        if (! is_string($expected) || ! is_string($given) || ! hash_equals($expected, $given)) {
            return redirect()
                ->route('settings.bank')
                ->with('bank_error', 'Innloggingen kunne ikke bekreftes. Prøv å koble til på nytt.');
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            return redirect()
                ->route('settings.bank')
                ->with('bank_error', 'SpareBank 1 sendte ikke en autorisasjonskode.');
        }

        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        try {
            $connection = $client->connect($user, $code, is_string($finInst) ? $finInst : '');
        } catch (Sparebank1Exception $exception) {
            return redirect()
                ->route('settings.bank')
                ->with('bank_error', $exception->getMessage());
        }

        try {
            $accounts($connection);
        } catch (Sparebank1Exception $exception) {
            return redirect()
                ->route('settings.bank')
                ->with('bank_error', 'Innloggingen er lagret, men kontolisten kunne ikke hentes. '.$exception->getMessage());
        }

        return redirect()
            ->route('settings.bank')
            ->with('bank_status', 'SpareBank 1 er koblet til. Velg hvilke kontoer som skal synkes.');
    }
}
