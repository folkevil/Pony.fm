<?php

	namespace Api\Web;

	use Commands\CreateAlbumCommand;
	use Commands\DeleteAlbumCommand;
	use Commands\DeleteTrackCommand;
	use Commands\EditAlbumCommand;
	use Commands\EditTrackCommand;
	use Cover;
	use Entities\Album;
	use Entities\Image;
	use Entities\Track;
	use Illuminate\Support\Facades\Auth;
	use Illuminate\Support\Facades\Input;
	use Illuminate\Support\Facades\Response;

	class AlbumsController extends \ApiControllerBase {
		public function postCreate() {
			return $this->execute(new CreateAlbumCommand(Input::all()));
		}

		public function postEdit($id) {
			return $this->execute(new EditAlbumCommand($id, Input::all()));
		}

		public function postDelete($id) {
			return $this->execute(new DeleteAlbumCommand($id));
		}

		public function getOwned() {
			$query = Album::summary()->where('user_id', \Auth::user()->id)->orderBy('created_at', 'desc')->get();
			$albums = [];
			foreach ($query as $album) {
				$albums[] = [
					'id' => $album->id,
					'title' => $album->title,
					'slug' => $album->slug,
					'created_at' => $album->created_at,
					'covers' => [
						'small' => $album->getCoverUrl(Image::SMALL),
						'normal' => $album->getCoverUrl(Image::NORMAL)
					]
				];
			}
			return Response::json($albums, 200);
		}

		public function getEdit($id) {
			$album = Album::with('tracks')->find($id);
			if (!$album)
				return $this->notFound('Album ' . $id . ' not found!');

			if ($album->user_id != Auth::user()->id)
				return $this->notAuthorized();

			$tracks = [];
			foreach ($album->tracks as $track) {
				$tracks[] = [
					'id' => $track->id,
					'title' => $track->title
				];
			}

			return Response::json([
				'id' => $album->id,
				'title' => $album->title,
				'user_id' => $album->user_id,
				'slug' => $album->slug,
				'created_at' => $album->created_at,
				'published_at' => $album->published_at,
				'description' => $album->description,
				'cover_url' => $album->hasCover() ? $album->getCoverUrl(Image::NORMAL) : null,
				'real_cover_url' => $album->getCoverUrl(Image::NORMAL),
				'tracks' => $tracks
			], 200);
		}
	}