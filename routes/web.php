<?php

use App\Http\Controllers\AnnualTaxReportController;
use App\Http\Controllers\Auth\CustomerGoogleAuthController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\ResendVerificationController;
use App\Http\Controllers\Auth\StaffVerifyEmailController;
use App\Http\Controllers\F24SheetController;
use App\Http\Controllers\IncomeImportSampleController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\TaxCalendarFeedController;
use App\Http\Controllers\TaxPaymentReceiptController;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Staff (panel) email verification. No auth middleware on purpose — the
// `signed` middleware secures it — so the link works when opened on a
// device the user isn't logged in on. Customers use `verification.verify`
// in routes/api.php instead. See App\Notifications\Auth\VerifyEmail.
Route::get('/admin/email/verify/{id}/{hash}', StaffVerifyEmailController::class)
    ->middleware(['signed', 'throttle:6,1'])
    ->name('staff.verification.verify');

// Two separate sign-ins, each with its own dashboard:
//  - staff:     /panel/login → /panel (Filament admin panel)
//  - customers: /login, /register → /app/tax (React portal)
// Signing in on the other one still works and lands on your own dashboard
// (see App\Filament\Auth\LoginResponse and the redirects below).
Route::get('/', function (Request $request) {
    $user = $request->user();

    return match (true) {
        $user === null => redirect()->route('customer.login'),
        $user->isStaff() => redirect()->to(Filament::getPanel('admin')->getUrl()),
        default => redirect()->route('customer.tax'),
    };
})->name('home');

// Customer-facing React auth screens — a thin Blade shell that mounts
// resources/js/customer-auth.jsx, which talks to the existing
// /api/v1/auth/* endpoints over the Sanctum SPA session.
Route::get('/login', fn () => view('customer.auth', ['mode' => 'login']))->name('customer.login');
Route::get('/register', fn () => view('customer.auth', ['mode' => 'register']))->name('customer.register');
// Earlier addresses, still in bookmarks and sent emails.
Route::permanentRedirect('/app/login', '/login');
Route::permanentRedirect('/app/register', '/register');

// Personal deadlines calendar (iCal) — secret-token URL, no session; the
// customer copies it from the account page into their calendar app.
Route::get('/calendar/{token}.ics', TaxCalendarFeedController::class)
    ->middleware('throttle:60,1')
    ->name('tax.calendar-feed');

// Customer account page — same React shell as the portal.
Route::get('/app/account', fn () => view('customer.tax', ['view' => 'account']))->name('customer.account');

// Customer tax dashboard — Blade shell for resources/js/customer-tax.jsx.
// Auth is enforced by the /api/v1/tax/* endpoints it calls (a 401 there
// sends the browser to /app/login), same as the auth screens above.
Route::get('/app/tax', function (Request $request) {
    // Staff have their own dashboard; a staff account with no customer
    // record linked would only see "not linked" here.
    $user = $request->user();
    if ($user?->isStaff() && $user->customer_id === null) {
        return redirect()->to(Filament::getPanel('admin')->getUrl());
    }

    return view('customer.tax');
})->name('customer.tax');

// Lands here with ?token=&email= in the query string — see
// App\Notifications\Auth\ResetPassword::resetUrl(), which builds exactly
// this URL (same FRONTEND_URL-points-at-this-app situation as /email-
// verified and /auth/callback below).
Route::get('/reset-password', fn () => view('customer.auth', ['mode' => 'reset-password']))->name('customer.password.reset');

// Landing pages for two server-side redirects that point at FRONTEND_URL
// (see EmailVerificationController and CustomerGoogleAuthController) —
// in this deployment FRONTEND_URL is this same app, not a separate SPA,
// so these need an actual route rather than 404ing once the browser lands
// here.
Route::get('/verify-email', fn () => view('customer.status', [
    'tone' => 'success',
    'title' => 'Check your email',
    'message' => 'Account created. Open the verification link in your email before signing in. If you cannot find it, check your spam folder.',
    'ctaLabel' => 'Back to sign in',
    'ctaHref' => route('customer.login'),
    'showResendVerification' => true,
]))->name('customer.verification-notice');

Route::post('/verify-email/resend', ResendVerificationController::class)
    ->middleware('throttle:6,1')
    ->name('customer.verification-resend');

Route::get('/email-verified', function (Request $request) {
    $alreadyVerified = $request->query('status') === 'already-verified';

    return view('customer.status', [
        'tone' => 'success',
        'title' => $alreadyVerified ? 'Already verified' : 'Email verified',
        'message' => $alreadyVerified
            ? 'This email address was already confirmed — you can sign in now.'
            : 'Your email address is confirmed. You can sign in now.',
        'ctaLabel' => 'Go to sign in',
        'ctaHref' => route('customer.login'),
    ]);
})->name('customer.email-verified');

Route::get('/auth/callback', function (Request $request) {
    $success = $request->query('status') === 'success';

    return view('customer.status', [
        'tone' => $success ? 'success' : 'error',
        'title' => $success ? 'Signed in' : 'Sign-in failed',
        'message' => $success
            ? "You're signed in with Google."
            : 'Something went wrong signing in with Google. Please try again.',
        'ctaLabel' => $success ? 'Continue' : 'Back to sign in',
        'ctaHref' => $success ? route('customer.tax') : route('customer.login'),
    ]);
})->name('customer.auth.callback');

Route::post('/locale', [LocaleController::class, 'update'])->middleware('throttle:30,1')->name('locale.update');

Route::prefix('admin/auth/google')->name('auth.google.')->middleware('throttle:30,1')->group(function () {
    Route::get('/redirect', [GoogleAuthController::class, 'redirect'])->name('redirect');
    Route::get('/callback', [GoogleAuthController::class, 'callback'])->name('callback');
});

// Customer-facing "Sign in with Google" — a full browser redirect flow
// (OAuth can't be an XHR call), separate from the admin one above so a
// customer login never assigns the admin's 'panel_user' role. See
// App\Http\Controllers\Auth\CustomerGoogleAuthController.
Route::prefix('auth/google')->name('customer.auth.google.')->middleware('throttle:30,1')->group(function () {
    Route::get('/redirect', [CustomerGoogleAuthController::class, 'redirect'])->name('redirect');
    Route::get('/callback', [CustomerGoogleAuthController::class, 'callback'])->name('callback');
});

// Receipts live on the private `local` disk, not `public` — this route is
// the only way to read one back, gated by a signed/expiring URL (see
// payment-table.blade.php) AND an authenticated staff session.
Route::get('/customers/{customer}/tax/payments/{type}/{id}/receipt', TaxPaymentReceiptController::class)
    ->middleware(['signed', 'auth'])
    ->name('tax.payments.receipt.download');

// Generated (not uploaded) "Conto Economico" annual income statement PDF —
// see App\Services\Tax\AnnualTaxReportService. Same gating as the receipt
// route above.
Route::get('/customers/{customer}/tax/{year}/annual-report', AnnualTaxReportController::class)
    ->middleware(['signed', 'auth'])
    ->name('tax.annual-report.download');

// Sample files for the income importer (invoices list / cash register as
// CSV or Excel, an e-invoice XML) — staff only.
Route::get('/tax/import-samples/{format}.{ext}', IncomeImportSampleController::class)
    ->middleware('auth')
    ->where(['format' => 'invoices|cash-register|e-invoice', 'ext' => 'csv|xlsx|xml'])
    ->name('tax.import-sample');

// F24 payment sheet for one customer and deadline (pay_on in the signed query).
Route::get('/customers/{customer}/tax/f24/{date}', F24SheetController::class)
    ->middleware(['signed', 'auth'])
    ->name('tax.f24-sheet.download');
