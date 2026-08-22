<?php

declare(strict_types=1);

use App\Http\Flash;
use App\Mail\Mailer;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\UserRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Views\Twig;

// Password reset stays plain/functional chrome, same as Login -- see
// spec.md -- Immersion Rules ("Login stays plain 'Login'").
return function (App $app): void {
    $container = $app->getContainer();

    if ($container === null) {
        throw new RuntimeException('Container must be set on the App before registering routes.');
    }

    $app->get('/password-reset', function (Request $request, Response $response): Response {
        return Twig::fromRequest($request)->render($response, 'auth/password_reset_request.twig');
    });

    $app->post('/password-reset', function (Request $request, Response $response) use ($container): Response {
        /** @var array<string, string> $data */
        $data = (array) $request->getParsedBody();
        $email = trim($data['email'] ?? '');

        /** @var UserRepository $userRepository */
        $userRepository = $container->get(UserRepository::class);
        $user = $userRepository->findByEmail($email);

        // Do the same amount of visible work either way, and always show
        // the same response -- don't let this endpoint reveal whether an
        // email address has an account (standard practice for reset flows).
        if ($user !== null) {
            /** @var PasswordResetTokenRepository $tokenRepository */
            $tokenRepository = $container->get(PasswordResetTokenRepository::class);
            $token = $tokenRepository->createForUser($user['id']);

            /** @var array{app_url: string} $settings */
            $settings = $container->get('settings');
            $resetUrl = rtrim($settings['app_url'], '/') . '/password-reset/' . $token;

            /** @var Mailer $mailer */
            $mailer = $container->get(Mailer::class);
            $mailer->send(
                $user['email'],
                'Reset your fantasy.recipes password',
                "Someone (hopefully you) asked to reset the password on this account.\n\n"
                . "Follow this link within the next hour to choose a new one:\n" . $resetUrl . "\n\n"
                . "If you didn't request this, you can safely ignore this email.",
            );
        }

        Flash::add(
            'success',
            'If an account exists for that email, a password reset link is on its way.',
        );

        return $response->withHeader('Location', '/password-reset')->withStatus(302);
    });

    $app->get('/password-reset/{token}', function (Request $request, Response $response, array $args) use ($container): Response {
        /** @var PasswordResetTokenRepository $tokenRepository */
        $tokenRepository = $container->get(PasswordResetTokenRepository::class);

        if ($tokenRepository->findValidUserId($args['token']) === null) {
            Flash::add('error', 'That password reset link is invalid or has expired. Request a new one below.');

            return $response->withHeader('Location', '/password-reset')->withStatus(302);
        }

        return Twig::fromRequest($request)->render($response, 'auth/password_reset_confirm.twig', [
            'token' => $args['token'],
        ]);
    });

    $app->post('/password-reset/{token}', function (Request $request, Response $response, array $args) use ($container): Response {
        $token = $args['token'];

        /** @var PasswordResetTokenRepository $tokenRepository */
        $tokenRepository = $container->get(PasswordResetTokenRepository::class);
        $userId = $tokenRepository->findValidUserId($token);

        if ($userId === null) {
            Flash::add('error', 'That password reset link is invalid or has expired. Request a new one below.');

            return $response->withHeader('Location', '/password-reset')->withStatus(302);
        }

        /** @var array<string, string> $data */
        $data = (array) $request->getParsedBody();
        $password = $data['password'] ?? '';

        if (strlen($password) < 8) {
            return Twig::fromRequest($request)->render($response->withStatus(422), 'auth/password_reset_confirm.twig', [
                'token' => $token,
                'errors' => ['Password must be at least 8 characters.'],
            ]);
        }

        /** @var UserRepository $userRepository */
        $userRepository = $container->get(UserRepository::class);
        $userRepository->updatePassword($userId, $password);

        // Consume all outstanding tokens for this user, not just the one
        // used -- a reset invalidates any other in-flight reset links too.
        $tokenRepository->deleteForUser($userId);

        Flash::add('success', 'Your password has been changed. You can log in with it now.');

        return $response->withHeader('Location', '/login')->withStatus(302);
    });
};
