<?php
declare(strict_types=1);

/*
 * Every API route in one place.
 *   ['auth' => true]                     login required
 *   ['auth' => 'optional']               works logged in or out
 *   ['auth' => true, 'roles' => [...]]   login + one of these roles (admin always allowed)
 */

use App\Controllers\AdminController;
use App\Controllers\AlbumController;
use App\Controllers\AuthController;
use App\Controllers\BeatController;
use App\Controllers\BookingController;
use App\Controllers\CreatorController;
use App\Controllers\DashboardController;
use App\Controllers\FileController;
use App\Controllers\HealthController;
use App\Controllers\MeController;
use App\Controllers\MentorController;
use App\Controllers\OrderController;
use App\Controllers\OpportunityController;
use App\Controllers\PayoutController;
use App\Controllers\ReportController;
use App\Controllers\ReviewController;
use App\Controllers\StudioController;
use App\Controllers\TrackController;
use App\Controllers\WebhookController;

/** @var App\Core\Router $router */

$auth     = ['auth' => true];
$optional = ['auth' => 'optional'];
$artist   = ['auth' => true, 'roles' => ['artist']];
$producer = ['auth' => true, 'roles' => ['producer']];
$creator  = ['auth' => true, 'roles' => ['artist', 'producer']];
$admin    = ['auth' => true, 'roles' => ['admin']];

// ---- System ----------------------------------------------------------------
$router->get('/api/health', [HealthController::class, 'health']);
$router->get('/api/meta',   [HealthController::class, 'meta']);

// ---- Sign-in ---------------------------------------------------------------
$router->post('/api/auth/register', [AuthController::class, 'register']);
$router->post('/api/auth/login',    [AuthController::class, 'login']);
$router->post('/api/auth/otp',      [AuthController::class, 'requestOtp']);
$router->post('/api/auth/verify',   [AuthController::class, 'verifyOtp']);
$router->post('/api/auth/logout',   [AuthController::class, 'logout'], $auth);

// ---- My account and wallet -------------------------------------------------
$router->get('/api/me',          [MeController::class, 'show'], $auth);
$router->patch('/api/me',        [MeController::class, 'update'], $auth);
$router->get('/api/me/wallet',   [MeController::class, 'wallet'], $auth);
$router->get('/api/me/library',  [MeController::class, 'library'], $auth);

// ---- Creators (artists and producers) --------------------------------------
$router->patch('/api/creators/me',         [CreatorController::class, 'upsert'], $auth);
$router->post('/api/creators/me/avatar',   [CreatorController::class, 'avatar'], $creator);
$router->get('/api/creators/{slug}',       [CreatorController::class, 'show'], $optional);
$router->post('/api/creators/{id}/follow', [CreatorController::class, 'follow'], $auth);
$router->delete('/api/creators/{id}/follow', [CreatorController::class, 'unfollow'], $auth);

// ---- Music mentors ------------------------------------------------------------
$router->get('/api/mentors', [MentorController::class, 'index']);
$router->patch('/api/mentors/me', [MentorController::class, 'upsert'], $creator);
$router->get('/api/mentors/{slug}', [MentorController::class, 'show']);
$router->post('/api/mentors/{id}/bookings', [MentorController::class, 'book'], $auth);
$router->get('/api/mentor-bookings', [MentorController::class, 'bookings'], $auth);
$router->patch('/api/mentor-bookings/{id}', [MentorController::class, 'bookingAction'], $auth);

// ---- Promotion and distribution opportunities ----------------------------------
$router->get('/api/opportunities', [OpportunityController::class, 'index']);
$router->get('/api/opportunities/me/applications', [OpportunityController::class, 'myApplications'], $artist);
$router->post('/api/opportunities/{id}/applications', [OpportunityController::class, 'apply'], $artist);
$router->get('/api/opportunities/{id}', [OpportunityController::class, 'show']);

// ---- Songs -----------------------------------------------------------------
$router->get('/api/tracks',                [TrackController::class, 'index'], $optional);
$router->get('/api/tracks/mine',           [TrackController::class, 'mine'], $artist);
$router->post('/api/tracks',               [TrackController::class, 'store'], $artist);
$router->get('/api/tracks/{id}',           [TrackController::class, 'show'], $optional);
$router->patch('/api/tracks/{id}',         [TrackController::class, 'update'], $artist);
$router->delete('/api/tracks/{id}',        [TrackController::class, 'destroy'], $artist);
$router->post('/api/tracks/{id}/cover',    [TrackController::class, 'cover'], $artist);
$router->get('/api/tracks/{id}/stream',    [TrackController::class, 'stream']);
$router->get('/api/tracks/{id}/preview',   [TrackController::class, 'preview']);
$router->post('/api/tracks/{id}/plays',    [TrackController::class, 'play'], $optional);
$router->post('/api/tracks/{id}/like',     [TrackController::class, 'like'], $auth);
$router->delete('/api/tracks/{id}/like',   [TrackController::class, 'unlike'], $auth);

$router->post('/api/albums',               [AlbumController::class, 'store'], $artist);
$router->get('/api/albums/{id}',           [AlbumController::class, 'show']);

// ---- Beat market -------------------------------------------------------------
$router->get('/api/beats',                 [BeatController::class, 'index']);
$router->get('/api/beats/mine',            [BeatController::class, 'mine'], $producer);
$router->post('/api/beats',                [BeatController::class, 'store'], $producer);
$router->get('/api/beats/{id}',            [BeatController::class, 'show'], $optional);
$router->patch('/api/beats/{id}',          [BeatController::class, 'update'], $producer);
$router->delete('/api/beats/{id}',         [BeatController::class, 'destroy'], $producer);
$router->get('/api/beats/{id}/preview',    [BeatController::class, 'preview']);
$router->post('/api/beats/{id}/plays',     [BeatController::class, 'play']);

// ---- Studios and booking ---------------------------------------------------------
$router->get('/api/studios',               [StudioController::class, 'index']);
$router->get('/api/studios/mine',          [StudioController::class, 'mine'], $auth);
$router->post('/api/studios',              [StudioController::class, 'store'], $auth);
$router->get('/api/studios/{id}',          [StudioController::class, 'show'], $optional);
$router->patch('/api/studios/{id}',        [StudioController::class, 'update'], $auth);
$router->post('/api/studios/{id}/cover',   [StudioController::class, 'cover'], $auth);
$router->post('/api/studios/{id}/rooms',   [StudioController::class, 'addRoom'], $auth);
$router->patch('/api/rooms/{id}',          [StudioController::class, 'updateRoom'], $auth);
$router->put('/api/rooms/{id}/hours',      [StudioController::class, 'setHours'], $auth);
$router->post('/api/rooms/{id}/blocked-dates', [StudioController::class, 'blockDate'], $auth);
$router->delete('/api/rooms/{id}/blocked-dates/{blockedId}', [StudioController::class, 'unblockDate'], $auth);
$router->get('/api/rooms/{id}/availability', [StudioController::class, 'availability']);

$router->post('/api/bookings',                 [BookingController::class, 'store'], $auth);
$router->get('/api/bookings',                  [BookingController::class, 'index'], $auth);
$router->get('/api/bookings/{id}',             [BookingController::class, 'show'], $auth);
$router->patch('/api/bookings/{id}',           [BookingController::class, 'action'], $auth);
$router->post('/api/bookings/{id}/pay-balance', [BookingController::class, 'payBalance'], $auth);

// ---- Checkout, downloads and payments ----------------------------------------
$router->post('/api/orders',                   [OrderController::class, 'store'], $auth);
$router->get('/api/orders',                    [OrderController::class, 'index'], $auth);
$router->get('/api/orders/{id}',               [OrderController::class, 'show'], $auth);
$router->get('/api/orders/{id}/downloads',     [OrderController::class, 'downloads'], $auth);
$router->get('/api/order-items/{id}/license',  [OrderController::class, 'license'], $auth);
$router->get('/api/files/{token}',             [FileController::class, 'download']);

$router->post('/api/webhooks/pawapay',         [WebhookController::class, 'pawapay']);

// ---- Creator dashboard and payouts ---------------------------------------------
$router->get('/api/dashboard/stats',  [DashboardController::class, 'stats'], $auth);
$router->post('/api/payouts',         [PayoutController::class, 'store'], $auth);
$router->get('/api/payouts',          [PayoutController::class, 'index'], $auth);

// ---- Reviews and reports -------------------------------------------------------
$router->get('/api/reviews',   [ReviewController::class, 'index']);
$router->post('/api/reviews',  [ReviewController::class, 'store'], $auth);
$router->post('/api/reports',  [ReportController::class, 'store'], $auth);

// ---- Admin ------------------------------------------------------------------
$router->get('/api/admin/overview',                [AdminController::class, 'overview'], $admin);
$router->get('/api/admin/queue',                   [AdminController::class, 'queue'], $admin);
$router->get('/api/admin/opportunities',            [OpportunityController::class, 'adminIndex'], $admin);
$router->post('/api/admin/opportunities',           [OpportunityController::class, 'adminCreate'], $admin);
$router->patch('/api/admin/opportunities/{id}',     [OpportunityController::class, 'adminUpdate'], $admin);
$router->get('/api/admin/opportunities/{id}/applications', [OpportunityController::class, 'applications'], $admin);
$router->patch('/api/admin/opportunities/{id}/applications/{applicationId}', [OpportunityController::class, 'reviewApplication'], $admin);
$router->post('/api/admin/tracks/{id}/review',     [AdminController::class, 'reviewTrack'], $admin);
$router->post('/api/admin/beats/{id}/review',      [AdminController::class, 'reviewBeat'], $admin);
$router->post('/api/admin/studios/{id}/review',    [AdminController::class, 'reviewStudio'], $admin);
$router->post('/api/admin/creators/{id}/verify',   [AdminController::class, 'verifyCreator'], $admin);
$router->get('/api/admin/payouts',                 [AdminController::class, 'payouts'], $admin);
$router->post('/api/admin/payouts/{id}/approve',   [AdminController::class, 'approvePayout'], $admin);
$router->post('/api/admin/payouts/{id}/reject',    [AdminController::class, 'rejectPayout'], $admin);
$router->get('/api/admin/reports',                 [AdminController::class, 'reports'], $admin);
$router->post('/api/admin/reports/{id}/resolve',   [AdminController::class, 'resolveReport'], $admin);
$router->get('/api/admin/users',                   [AdminController::class, 'users'], $admin);
$router->patch('/api/admin/users/{id}',            [AdminController::class, 'updateUser'], $admin);
