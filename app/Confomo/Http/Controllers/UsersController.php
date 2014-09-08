<?php namespace Confomo\Http\Controllers;

use App;
use Auth;
use Cache;
use Confomo\Entities\User;
use Input;
use Log;
use Redirect;
use Request;
use Validator;
use View;

class UsersController extends BaseController
{
	protected $throttle_key;
	protected $throttle_max_requests;
	protected $throttle_duration;

	public function __construct()
	{
		$this->throttle_key = sprintf('loginThrottle:%s', Request::getClientIp());
		$this->throttle_max_requests = 15;
		$this->throttle_duration = 15;
	}

	public function login()
	{
		return View::make('users.login');
	}

	protected function guardRateLimit()
	{
		if (Cache::get($this->throttle_key) > $this->throttle_max_requests)
		{
			Log::error('User hit failed login rate limit.', ['ip' =>  Request::getClientIp(), 'email' => Input::get('email')]);
			App::abort(429);
		}
	}

	protected function incrementRateLimitGuard()
	{
		Cache::add($this->throttle_key, 0, $this->throttle_duration);

		// Manually increment (file can't increment)
		$prev = Cache::get($this->throttle_key);
		$new = $prev + 1;

		// Add to count. Bummer is that this extends the previous throttle
		Cache::put($this->throttle_key, $new, $this->throttle_duration);
	}

	public function postLogin()
	{
		$this->guardRateLimit();

		$user = array(
			'email' => Input::get('email'),
			'password' => Input::get('password')
		);

		if (Auth::attempt($user)) {
			return Redirect::route('home')
				->with('flash_notice', 'You are successfully logged in.');
		}

		$this->incrementRateLimitGuard();

		return Redirect::route('login')
			->with('flash_error', 'Your email/password combination was incorrect.')
			->withInput();
	}

	public function logout()
	{
		Auth::logout();
		return Redirect::route('home');
	}

	public function account()
	{
		return View::make('users.edit')
			->with('user', Auth::user());
	}

	public function postAccount()
	{
		$current_user = Auth::user();

		// Surprise, validating in controller = ugly and un-DRY @todo
		$validator = Validator::make(
			Input::all(),
			[
				'email' => 'required|min:5|email|unique:users,email,' . $current_user->id,
				'username' => 'min:3|alpha_dash|unique:users,username,' . $current_user->id
			]
		);

		if ($validator->fails())
		{
			return Redirect::route('account')
				->with('flash_error', 'Sorry, but there was a problem with your edit.')
				->withErrors($validator)
				->withInput();
		}

		if (Input::get('password') != '')
		{
			$current_user->password = Hash::make(Input::get('password'));
		}

		$current_user->email = Input::get('email');
		$current_user->username = Input::get('username');

		$current_user->save();

		return Redirect::route('account');
	}

	public function signup()
	{
		return View::make('users.create');
	}

	public function postSignup()
	{
		// Surprise, validating in controller = ugly and un-DRY @todo
		$validator = Validator::make(
			Input::all(),
			[
				'email' => 'required|min:5|email|unique:users',
				'password' => 'required|min:5',
				'username' => 'min:3|unique:users|alpha_dash'
			]
		);

		if ($validator->fails())
		{
			return Redirect::route('signup')
				->with('flash_error', 'Sorry, but there was a problem signing you up.')
				->withErrors($validator)
				->withInput();
		}

		$user = User::create([
			'email' => Input::get('email'),
			'password' => Hash::make(Input::get('password'))
		]);

		if (Auth::attempt([
			'email' => Input::get('email'),
			'password' => Input::get('password'),
			'username' => Input::get('username')
		])) {
			return Redirect::route('home')
				->with('flash_notice', 'You have successfully created a user account.');
		}

		return Redirect::route('signup')
			->with('flash_error', 'Sorry, but there was a problem signing you up.')
			->withInput();
	}
}
