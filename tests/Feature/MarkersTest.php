<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;

const LHE_H = '<template id="lhe-service-up-header">UP</template>';
const LHE_F = '<template id="lhe-service-up-footer">UP</template>';

beforeEach(function () {
    View::addLocation(__DIR__.'/../Fixtures/views');

    $html = fn (string $body, array $headers = [], int $status = 200) => fn () => response($body, $status, ['Content-Type' => 'text/html'] + $headers);

    Route::middleware('web')->group(function () use ($html) {
        Route::get('/page', $html('<html><head><title>x</title></head><body>hi</body></html>'));
        Route::get('/upper', $html('<HTML><HEAD lang="en"><title>x</title></HEAD><BODY>hi</BODY></HTML>'));
        Route::get('/last', $html('<html><head></head><body><script>"</body>"</script></body></html>'));
        Route::get('/header-tag', $html('<html><header class="a">h</header><head></head><body></body></html>'));
        Route::get('/only-header-tag', $html('<header>h</header><body></body>'));
        Route::get('/nohead', $html('<body>hi</body>'));
        Route::get('/nobody', $html('<head></head>hi'));
        Route::get('/length', $html('<head></head><body></body>', ['Content-Length' => '25']));
        Route::get('/attachment', $html('<head></head><body></body>', ['Content-Disposition' => 'attachment; filename="a.html"']));
        Route::get('/created', $html('<head></head><body></body>', [], 201));
        Route::get('/json', fn () => response()->json(['a' => '<head></head><body></body>']));
        Route::get('/stream', fn () => response()->stream(fn () => print ('<head></head><body></body>'), 200, ['Content-Type' => 'text/html']));
        Route::get('/gzip', $html('<head></head><body></body>', ['Content-Encoding' => 'gzip']));
        Route::get('/empty', fn () => response('', 204, ['Content-Type' => 'text/html']));
        Route::get('/redirect', fn () => redirect('/page'));
        Route::get('/view', fn () => view('directive'));
        Route::get('/plain', fn () => response('<head></head><body></body>', 200, ['Content-Type' => 'text/plain']));
    });
});

it('injects both markers', fn () => $this->get('/page')
    ->assertSee('<head>'.LHE_H.'<title>', false)
    ->assertSee(LHE_F.'</body>', false));

it('handles uppercase and attributes', fn () => $this->get('/upper')
    ->assertSee('<HEAD lang="en">'.LHE_H, false)
    ->assertSee(LHE_F.'</BODY>', false));

it('uses the last body tag', function () {
    $content = $this->get('/last')->getContent();

    expect($content)->toContain('"</body>"</script>'.LHE_F.'</body>')
        ->and(substr_count($content, LHE_F))->toBe(1);
});

it('never matches the header element as head', function () {
    $content = $this->get('/header-tag')->getContent();
    expect($content)->toContain('<head>'.LHE_H.'</head>')
        ->and($content)->toContain('<header class="a">h</header>');

    $this->get('/only-header-tag')->assertDontSee(LHE_H, false)->assertSee(LHE_F, false);
});

it('skips non-eligible responses', fn (string $route) => $this->get($route)
    ->assertDontSee('lhe-service-up', false))
    ->with(['/json', '/stream', '/gzip', '/empty', '/redirect', '/attachment', '/plain']);

it('injects into other 2xx html responses', fn () => $this->get('/created')->assertSee(LHE_H, false));

it('skips missing anchors independently', function () {
    $this->get('/nohead')->assertDontSee(LHE_H, false)->assertSee(LHE_F, false);
    $this->get('/nobody')->assertSee(LHE_H, false)->assertDontSee(LHE_F, false);
});

it('does not duplicate when directives are used', function () {
    $content = $this->get('/view')->getContent();

    expect(substr_count($content, LHE_H))->toBe(1)
        ->and(substr_count($content, LHE_F))->toBe(1);
});

it('directives read config at render time', function () {
    config(['laravel-health-endpoints.markers.enabled' => false, 'laravel-health-endpoints.markers.header' => '<b>H1</b>']);

    $this->get('/view')->assertSee('<head><b>H1</b><title>', false)->assertSee(LHE_F, false);
});

it('respects overrides without interpreting backslashes or dollar groups', function () {
    config(['laravel-health-endpoints.markers.header' => '<i data-x="$1 \\0 \\1">H</i>']);

    $this->get('/page')->assertSee('<head><i data-x="$1 \\0 \\1">H</i><title>', false);
});

it('skips a marker when its string is empty', function () {
    config(['laravel-health-endpoints.markers.header' => '']);

    $this->get('/page')->assertDontSee(LHE_H, false)->assertSee(LHE_F, false);
});

it('does nothing when disabled', function () {
    config(['laravel-health-endpoints.markers.enabled' => false]);

    $this->get('/page')->assertDontSee('lhe-service-up', false);
});

it('updates content length', function () {
    $response = $this->get('/length');

    expect($response->headers->get('Content-Length'))->toBe((string) strlen($response->getContent()));
});

it('does not touch health endpoints', fn () => $this->getJson('/lhe/v1/ping')->assertOk()->assertJsonMissing(['lhe-service-up']));
