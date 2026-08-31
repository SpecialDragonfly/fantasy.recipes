<?php

declare(strict_types=1);

use App\Auth\SessionAuth;
use App\Http\Flash;
use App\Repository\UserRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Views\Twig;

// Registration is open self-serve, login stays plain "Login" (not
// immersive) per spec.md -- Immersion Rules / Roles & Permissions.
return function (App $app): void {
    $container = $app->getContainer();

    if ($container === null) {
        throw new RuntimeException('Container must be set on the App before registering routes.');
    }

    $app->get('/register', function (Request $request, Response $response): Response {
        return Twig::fromRequest($request)->render($response, 'auth/register.twig');
    });

    $app->post('/register', function (Request $request, Response $response) use ($container): Response {
        /** @var array<string, string> $data */
        $data = (array) $request->getParsedBody();
        $username = trim($data['username'] ?? '');
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';
        // Unticked by default; only present in the body when the box is ticked.
        $marketingOptIn = isset($data['marketing_opt_in']);

        $errors = [];

        if (preg_match('/^[A-Za-z0-9_-]{3,64}$/', $username) !== 1) {
            $errors[] = 'Username must be 3-64 characters: letters, numbers, - or _ only.';
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Enter a valid email address.';
        }

        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }

        /** @var UserRepository $userRepository */
        $userRepository = $container->get(UserRepository::class);

        if ($errors === [] && $userRepository->findByUsername($username) !== null) {
            $errors[] = 'That username is already taken.';
        }

        if ($errors === [] && $userRepository->findByEmail($email) !== null) {
            $errors[] = 'An account with that email already exists.';
        }

        if ($errors !== []) {
            return Twig::fromRequest($request)->render($response->withStatus(422), 'auth/register.twig', [
                'errors' => $errors,
                'old' => ['username' => $username, 'email' => $email, 'marketing_opt_in' => $marketingOptIn],
            ]);
        }

        $userId = $userRepository->create($username, $email, $password, $marketingOptIn);
        $user = $userRepository->findById($userId);

        if ($user !== null) {
            SessionAuth::login($user);
            $userRepository->touchLastLogin($userId);
        }

        Flash::add('success', sprintf('Welcome to fantasy.recipes, %s.', $username));

        return $response->withHeader('Location', '/')->withStatus(302);
    });

    $app->get('/login', function (Request $request, Response $response): Response {
        $next = $request->getQueryParams()['next'] ?? null;

        return Twig::fromRequest($request)->render($response, 'auth/login.twig', ['next' => $next]);
    });

    $app->post('/login', function (Request $request, Response $response) use ($container): Response {
        /** @var array<string, string> $data */
        $data = (array) $request->getParsedBody();
        $identifier = trim($data['identifier'] ?? '');
        $password = $data['password'] ?? '';
        $next = $request->getQueryParams()['next'] ?? null;

        /** @var UserRepository $userRepository */
        $userRepository = $container->get(UserRepository::class);

        $user = str_contains($identifier, '@')
            ? $userRepository->findByEmail($identifier)
            : $userRepository->findByUsername($identifier);

        if ($user === null || !$userRepository->verifyPassword($user, $password)) {
            return Twig::fromRequest($request)->render($response->withStatus(422), 'auth/login.twig', [
                'errors' => ['Incorrect username/email or password.'],
                'old' => ['identifier' => $identifier],
                'next' => $next,
            ]);
        }

        SessionAuth::login($user);
        $userRepository->touchLastLogin((int) $user['id']);
        Flash::add('success', sprintf('Welcome back, %s.', $user['username']));

        $redirectTo = '/';
        if (is_string($next) && str_starts_with($next, '/') && !str_starts_with($next, '//')) {
            $redirectTo = $next;
        }

        return $response->withHeader('Location', $redirectTo)->withStatus(302);
    });

    $app->post('/logout', function (Request $request, Response $response): Response {
        SessionAuth::logout();
        Flash::add('info', 'You have been logged out.');

        return $response->withHeader('Location', '/')->withStatus(302);
    });
};
