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
use App\Controllers\HomeController;
use App\Controllers\MeController;
use App\Controllers\MentorController;
use App\Controllers\OrderController;
use App\Controllers\PaymentController;
use App\Controllers\OpportunityController;
use App\Controllers\PayoutController;
use App\Controllers\QuestionController;
use App\Controllers\ReportController;
use App\Controllers\ReviewController;
use App\Controllers\StudioController;
use App\Controllers\SpotifyController;
use App\Controllers\TrackController;
use App\Controllers\WebhookController;

/** @var App\Core\Router $router */

$auth     = ['auth' => true];
$optional = ['auth' => 'optional'];
$artist   = ['auth' => true, 'roles' => ['artist']];
$musician = ['auth' => true, 'roles' => ['artist', 'choir']];          // can publish songs
$producer = ['auth' => true, 'roles' => ['producer']];
$creator  = ['auth' => true, 'roles' => ['artist', 'producer', 'choir', 'manager', 'vocal_coach']];
$studioOwner = ['auth' => true, 'roles' => ['studio_owner']];
$manager  = ['auth' => true, 'roles' => ['manager']];
$admin    = ['auth' => true, 'roles' => ['admin']];

// ---- System ----------------------------------------------------------------
$router->get('/api/health', [HealthController::class, 'health']);
$router->get('/api/meta',   [HealthController::class, 'meta']);
$router->get('/api/home/featured-spotify', [HomeController::class, 'featuredSpotify']);
$router->get('/api/spotify/search', [SpotifyController::class, 'search']);

// ---- Sign-in ---------------------------------------------------------------
$router->post('/api/auth/register',         [AuthController::class, 'register']);
$router->get('/api/auth/username',          [AuthController::class, 'usernameAvailable']);
$router->post('/api/auth/login',            [AuthController::class, 'login']);           // email or username + password
$router->post('/api/auth/login/verify',     [AuthController::class, 'verifyLogin']);     // + the code from the email
$router->post('/api/auth/login/resend',     [AuthController::class, 'resendLogin']);
$router->post('/api/auth/password/forgot',  [AuthController::class, 'forgotPassword']);
$router->post('/api/auth/password/resend',  [AuthController::class, 'resendReset']);
$router->post('/api/auth/password/reset',   [AuthController::class, 'resetPassword']);
$router->post('/api/auth/otp',              [AuthController::class, 'phoneSignInRemoved']);   // 410: phone sign-in removed
$router->post('/api/auth/verify',           [AuthController::class, 'phoneSignInRemoved']);
$router->post('/api/auth/logout',           [AuthController::class, 'logout'], $auth);

// ---- My account and wallet -------------------------------------------------
$router->get('/api/me',          [MeController::class, 'show'], $auth);
$router->patch('/api/me',        [MeController::class, 'update'], $auth);
$router->get('/api/me/wallet',   [MeController::class, 'wallet'], $auth);
$router->get('/api/me/library',  [MeController::class, 'library'], $auth);

// ---- Creators (artists and producers) --------------------------------------
$router->patch('/api/creators/me',         [CreatorController::class, 'upsert'], $auth);
$router->get('/api/creators/me/manager',   [CreatorController::class, 'manager'], $musician);
$router->patch('/api/creators/me/manager', [CreatorController::class, 'saveManager'], $musician);
$router->delete('/api/creators/me/manager',[CreatorController::class, 'removeManager'], $musician);
$router->post('/api/creators/me/avatar',   [CreatorController::class, 'avatar'], $creator);
$router->get('/api/creators',              [CreatorController::class, 'index']);
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
$router->get('/api/opportunities/me/applications', [OpportunityController::class, 'myApplications'], $musician);
$router->post('/api/opportunities/{id}/applications', [OpportunityController::class, 'apply'], $musician);
$router->get('/api/opportunities/{id}', [OpportunityController::class, 'show']);

// ---- Songs -----------------------------------------------------------------
$router->get('/api/tracks',                [TrackController::class, 'index'], $optional);
$router->get('/api/tracks/mine',           [TrackController::class, 'mine'], $musician);
$router->post('/api/tracks',               [TrackController::class, 'store'], $musician);
$router->get('/api/tracks/{id}',           [TrackController::class, 'show'], $optional);
$router->patch('/api/tracks/{id}',         [TrackController::class, 'update'], $musician);
$router->post('/api/tracks/{id}/resubmit',  [TrackController::class, 'resubmit'], $musician);
$router->delete('/api/tracks/{id}',        [TrackController::class, 'destroy'], $musician);
$router->post('/api/tracks/{id}/cover',    [TrackController::class, 'cover'], $musician);
$router->get('/api/tracks/{id}/stream',    [TrackController::class, 'stream']);
$router->get('/api/tracks/{id}/preview',   [TrackController::class, 'preview']);
$router->post('/api/tracks/{id}/plays',    [TrackController::class, 'play'], $optional);
$router->post('/api/tracks/{id}/like',     [TrackController::class, 'like'], $auth);
$router->post('/api/tracks/{id}/save',     [TrackController::class, 'save'], $auth);
$router->delete('/api/tracks/{id}/save',   [TrackController::class, 'unsave'], $auth);
$router->post('/api/tracks/{id}/share',    [TrackController::class, 'share'], $optional);
$router->get('/api/tracks/{id}/download',  [TrackController::class, 'download'], $auth);
$router->delete('/api/tracks/{id}/like',   [TrackController::class, 'unlike'], $auth);

$router->post('/api/albums',               [AlbumController::class, 'store'], $musician);
$router->get('/api/albums/{id}',           [AlbumController::class, 'show']);

// ---- Beat market -------------------------------------------------------------
$router->get('/api/beats',                 [BeatController::class, 'index']);
$router->get('/api/beats/mine',            [BeatController::class, 'mine'], $producer);
$router->post('/api/beats',                [BeatController::class, 'store'], $producer);
$router->get('/api/beats/{id}',            [BeatController::class, 'show'], $optional);
$router->patch('/api/beats/{id}',          [BeatController::class, 'update'], $producer);
$router->post('/api/beats/{id}/resubmit',   [BeatController::class, 'resubmit'], $producer);
$router->delete('/api/beats/{id}',         [BeatController::class, 'destroy'], $producer);
$router->post('/api/beats/{id}/cover',     [BeatController::class, 'cover'], $producer);
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
$router->post('/api/rooms/{id}/photo',     [StudioController::class, 'roomPhoto'], $auth);
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
$router->get('/api/media/{token}',             [FileController::class, 'listen']);   // owner/admin preview of unpublished audio

$router->get('/api/payments',                  [PaymentController::class, 'index'], $auth);     // my payment history
$router->get('/api/payments/{reference}',      [PaymentController::class, 'show'], $auth);      // return page after Paystack
$router->post('/api/webhooks/paystack',        [WebhookController::class, 'paystack']);
$router->post('/api/webhooks/pawapay',         [WebhookController::class, 'pawapay']);

// ---- Creator dashboard and payouts ---------------------------------------------
$router->get('/api/dashboard/stats',  [DashboardController::class, 'stats'], $auth);
$router->get('/api/dashboard/wallet', [DashboardController::class, 'wallet'], $auth);
$router->get('/api/dashboard/music',  [DashboardController::class, 'musicDashboard'], $musician);
$router->get('/api/dashboard/beats',  [DashboardController::class, 'beatsDashboard'], $producer);
$router->get('/api/dashboard/studio', [DashboardController::class, 'studioDashboard'], $studioOwner);
$router->get('/api/dashboard/manager',[DashboardController::class, 'manager'], $manager);
$router->post('/api/payouts',         [PayoutController::class, 'store'], $auth);
$router->get('/api/payouts',          [PayoutController::class, 'index'], $auth);

// ---- Q&A -------------------------------------------------------------------------
$router->post('/api/questions',          [QuestionController::class, 'store'], $optional);
$router->get('/api/questions/mine',      [QuestionController::class, 'mine'], $auth);
$router->get('/api/questions/public',    [QuestionController::class, 'publicList']);

// ---- Reviews and reports -------------------------------------------------------
$router->get('/api/reviews',   [ReviewController::class, 'index']);
$router->post('/api/reviews',  [ReviewController::class, 'store'], $auth);
$router->post('/api/reports',  [ReportController::class, 'store'], $auth);

// ---- Admin ------------------------------------------------------------------
$router->get('/api/admin/overview',                [AdminController::class, 'overview'], $admin);
$router->get('/api/admin/activity',                [AdminController::class, 'activity'], $admin);
$router->get('/api/admin/bookings',                [AdminController::class, 'bookings'], $admin);
$router->post('/api/admin/onboard',                [AdminController::class, 'onboard'], $admin);
$router->put('/api/admin/managers/{id}/artists',   [AdminController::class, 'setManagerArtists'], $admin);
$router->get('/api/admin/creator-profiles',        [AdminController::class, 'creatorProfiles'], $admin);
$router->post('/api/admin/creators',                [AdminController::class, 'onboardCreator'], $admin);
$router->get('/api/admin/tracks',                  [AdminController::class, 'tracks'], $admin);
$router->get('/api/admin/beats',                   [AdminController::class, 'beats'], $admin);
$router->get('/api/admin/queue',                   [AdminController::class, 'queue'], $admin);
$router->get('/api/admin/studios',                 [AdminController::class, 'studios'], $admin);
$router->delete('/api/admin/studios/{id}',         [AdminController::class, 'archiveStudio'], $admin);
$router->post('/api/admin/studios/{id}/restore',   [AdminController::class, 'restoreStudio'], $admin);
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
$router->post('/api/admin/payouts/{id}/mark-sent', [AdminController::class, 'markPayoutSent'], $admin);
$router->get('/api/admin/reports',                 [AdminController::class, 'reports'], $admin);
$router->post('/api/admin/reports/{id}/resolve',   [AdminController::class, 'resolveReport'], $admin);
$router->get('/api/admin/questions',               [QuestionController::class, 'adminIndex'], $admin);
$router->get('/api/admin/questions/{id}',          [QuestionController::class, 'adminShow'], $admin);
$router->post('/api/admin/questions/{id}/answer',  [QuestionController::class, 'answer'], $admin);
$router->patch('/api/admin/questions/{id}',        [QuestionController::class, 'update'], $admin);
$router->get('/api/admin/featured-spotify',        [HomeController::class, 'adminIndex'], $admin);
$router->post('/api/admin/featured-spotify',       [HomeController::class, 'adminCreate'], $admin);
$router->patch('/api/admin/featured-spotify/{id}', [HomeController::class, 'adminUpdate'], $admin);
$router->delete('/api/admin/featured-spotify/{id}',[HomeController::class, 'adminDelete'], $admin);
$router->get('/api/admin/users',                   [AdminController::class, 'users'], $admin);
$router->patch('/api/admin/users/{id}',            [AdminController::class, 'updateUser'], $admin);
