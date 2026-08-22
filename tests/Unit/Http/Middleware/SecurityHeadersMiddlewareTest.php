<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Middleware;

use App\Http\Middleware\SecurityHeadersMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class SecurityHeadersMiddlewareTest extends TestCase
{
    public function testAddsXFrameOptionsSameoriginToTheResponse(): void
    {
        $middleware = new SecurityHeadersMiddleware();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/recipes/focaccia');

        $handler = new class implements Handler {
            public function handle(Request $request): Response
            {
                return (new ResponseFactory())->createResponse(200);
            }
        };

        $response = $middleware->process($request, $handler);

        self::assertSame('SAMEORIGIN', $response->getHeaderLine('X-Frame-Options'));
    }

    public function testPreservesTheRestOfTheHandlerResponse(): void
    {
        $middleware = new SecurityHeadersMiddleware();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/recipes/focaccia');

        $handler = new class implements Handler {
            public function handle(Request $request): Response
            {
                $response = (new ResponseFactory())->createResponse(404)->withHeader('X-Custom', 'kept');
                $response->getBody()->write('not found');

                return $response;
            }
        };

        $response = $middleware->process($request, $handler);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('kept', $response->getHeaderLine('X-Custom'));
        self::assertSame('not found', (string) $response->getBody());
        self::assertSame('SAMEORIGIN', $response->getHeaderLine('X-Frame-Options'));
    }
}
