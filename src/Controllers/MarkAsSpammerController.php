<?php

/*
 * This file is part of fof/spamblock.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\Spamblock\Controllers;

use Carbon\Carbon;
use Flarum\Discussion\Command\DeleteDiscussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Flags\Command\DeleteFlags;
use Flarum\Http\RequestUtil;
use Flarum\Post\Command\DeletePost;
use Flarum\User\Command\DeleteUser;
use Flarum\User\User;
use FoF\Spamblock\Event\MarkedUserAsSpammer;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Events\Dispatcher as EventsDispatcher;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class MarkAsSpammerController implements RequestHandlerInterface
{
	/**
	 * @var Dispatcher
	 */
	protected $bus;

	/**
	 * @var EventsDispatcher
	 */
	protected $events;

	/**
	 * @var ExtensionManager
	 */
	protected $extensions;

	/**
	 * @param EventsDispatcher $events
	 * @param Dispatcher	   $bus
	 * @param ExtensionManager $extensions
	 */
	public function __construct(Dispatcher $bus, EventsDispatcher $events, ExtensionManager $extensions)
	{
		$this->bus = $bus;
		$this->events = $events;
		$this->extensions = $extensions;
	}

	/**
	 * Handle the request and return a response.
	 *
	 * @param ServerRequestInterface $request
	 *
	 * @return ResponseInterface
	 */
	public function handle(ServerRequestInterface $request): ResponseInterface
	{
		$actor = RequestUtil::getActor($request);

		$userId = Arr::get($request->getQueryParams(), 'id');
		$user = User::findOrFail($userId);

		$actor->assertCan('spamblock', $user);

		$user->discussions()->chunk(50, function ($discussions) use ($actor) {
			foreach ($discussions as $discussion) {
				$this->bus->dispatch(
					new DeleteDiscussion($discussion->id, $actor)
				);
			}
		});

		$user->posts()->chunk(50, function ($posts) use ($actor) {
			foreach ($posts as $post) {
				$this->bus->dispatch(
					new DeletePost($post->id, $actor)
				);
			}
		});

		$this->bus->dispatch(
			 new DeleteUser($user->id, $actor)
		);

		$this->events->dispatch(
			new MarkedUserAsSpammer($user, $actor)
		);

		return (new Response())->withStatus(204);
	}
}
