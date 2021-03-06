<?php

namespace Laravel\Airlock\Tests;

use DateTimeInterface;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Airlock\Airlock;
use Laravel\Airlock\AirlockServiceProvider;
use Laravel\Airlock\Guard;
use Laravel\Airlock\HasApiTokens;
use Laravel\Airlock\PersonalAccessToken;
use Mockery;
use Orchestra\Testbench\TestCase;
use stdClass;

class GuardTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'testbench');

        $app['config']->set('database.connections.testbench', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }

    public function tearDown() : void
    {
        parent::tearDown();

        Mockery::close();
    }

    public function test_authentication_is_attempted_with_web_middleware()
    {
        $factory = Mockery::mock(AuthFactory::class);

        $guard = new Guard($factory);

        $webGuard = Mockery::mock(stdClass::class);

        $factory->shouldReceive('guard')
                ->with('web')
                ->andReturn($webGuard);

        $webGuard->shouldReceive('user')->once()->andReturn($fakeUser = new User);
        $webGuard->shouldReceive('getProvider->getModel')->once()->andReturn(User::class);

        $user = $guard->__invoke(Request::create('/', 'GET'));

        $this->assertTrue($user === $fakeUser);
        $this->assertTrue($user->tokenCan('foo'));
    }

    public function test_authentication_is_attempted_with_token_if_no_session_present()
    {
        $this->artisan('migrate', ['--database' => 'testbench'])->run();

        $factory = Mockery::mock(AuthFactory::class);

        $guard = new Guard($factory);

        $webGuard = Mockery::mock(stdClass::class);

        $factory->shouldReceive('guard')
                ->with('web')
                ->andReturn($webGuard);

        $webGuard->shouldReceive('user')->once()->andReturn(null);
        $webGuard->shouldReceive('getProvider->getModel')->andReturn(User::class);

        $request = Request::create('/', 'GET');
        $request->headers->set('Authorization', 'Bearer test');

        $user = $guard->__invoke($request);

        $this->assertNull($user);
    }

    public function test_authentication_with_token_fails_if_expired()
    {
        Airlock::useUserModel(User::class);

        $this->loadLaravelMigrations(['--database' => 'testbench']);
        $this->artisan('migrate', ['--database' => 'testbench'])->run();

        $factory = Mockery::mock(AuthFactory::class);

        $guard = new Guard($factory, 1);

        $webGuard = Mockery::mock(stdClass::class);

        $factory->shouldReceive('guard')
                ->with('web')
                ->andReturn($webGuard);

        $webGuard->shouldReceive('user')->once()->andReturn(null);
        $webGuard->shouldReceive('getProvider->getModel')->andReturn(User::class);

        $request = Request::create('/', 'GET');
        $request->headers->set('Authorization', 'Bearer test');

        $user = User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
            'remember_token' => Str::random(10),
        ]);

        $token = PersonalAccessToken::forceCreate([
            'user_id' => $user->id,
            'name' => 'Test',
            'token' => hash('sha256', 'test'),
            'created_at' => now()->subMinutes(60),
        ]);

        $user = $guard->__invoke($request);

        $this->assertNull($user);
    }

    public function test_authentication_is_successful_with_token_if_no_session_present()
    {
        Airlock::useUserModel(User::class);

        $this->loadLaravelMigrations(['--database' => 'testbench']);
        $this->artisan('migrate', ['--database' => 'testbench'])->run();

        $factory = Mockery::mock(AuthFactory::class);

        $guard = new Guard($factory);

        $webGuard = Mockery::mock(stdClass::class);

        $factory->shouldReceive('guard')
                ->with('web')
                ->andReturn($webGuard);

        $webGuard->shouldReceive('user')->once()->andReturn(null);
        $webGuard->shouldReceive('getProvider->getModel')->andReturn(User::class);

        $request = Request::create('/', 'GET');
        $request->headers->set('Authorization', 'Bearer test');

        $user = User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
            'remember_token' => Str::random(10),
        ]);

        $token = PersonalAccessToken::forceCreate([
            'user_id' => $user->id,
            'name' => 'Test',
            'token' => hash('sha256', 'test'),
        ]);

        $returnedUser = $guard->__invoke($request);

        $this->assertEquals($user->id, $returnedUser->id);
        $this->assertEquals($token->id, $returnedUser->currentAccessToken()->id);
        $this->assertInstanceOf(DateTimeInterface::class, $returnedUser->currentAccessToken()->last_used_at);
    }

    protected function getPackageProviders($app)
    {
        return [AirlockServiceProvider::class];
    }
}

class User extends Model
{
    use HasApiTokens;
}
