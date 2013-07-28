<?php

	namespace Entities;

	use Cover;
	use External;
	use getid3_writetags;
	use Illuminate\Support\Facades\Log;
	use Illuminate\Support\Str;
	use Whoops\Example\Exception;
	use Traits\SlugTrait;

	class Track extends \Eloquent {
		protected $softDelete = true;

		use SlugTrait;

		public static $Formats = [
			'FLAC' 		 => ['extension' => 'flac', 	'tag_format' => 'metaflac', 		'tag_method' => 'updateTagsWithGetId3', 'mime_type' => 'audio/flac', 'command' => 'ffmpeg 2>&1 -y -i {$source} -acodec flac -aq 8 -f flac {$target}'],
			'MP3' 		 => ['extension' => 'mp3', 		'tag_format' => 'id3v2.3', 			'tag_method' => 'updateTagsWithGetId3', 'mime_type' => 'audio/mpeg', 'command' => 'ffmpeg 2>&1 -y -i {$source} -acodec libmp3lame -ab 320k -f mp3 {$target}'],
			'OGG Vorbis' => ['extension' => 'ogg', 		'tag_format' => 'vorbiscomment',	'tag_method' => 'updateTagsWithGetId3', 'mime_type' => 'audio/ogg',  'command' => 'ffmpeg 2>&1 -y -i {$source} -acodec libvorbis -aq 7 -f ogg {$target}'],
			'AAC'  		 => ['extension' => 'm4a', 		'tag_format' => 'AtomicParsley', 	'tag_method' => 'updateTagsWithAtomicParsley', 'mime_type' => 'audio/mp4',  'command' => 'ffmpeg 2>&1 -y -i {$source} -acodec libfaac -ab 256k -f mp4 {$target}'],
			'ALAC' 		 => ['extension' => 'alac.m4a', 'tag_format' => 'AtomicParsley', 	'tag_method' => 'updateTagsWithAtomicParsley', 'mime_type' => 'audio/mp4',  'command' => 'ffmpeg 2>&1 -y -i {$source} -acodec alac {$target}'],
		];

		public static function summary() {
			return self::select('id', 'title', 'user_id', 'slug', 'is_vocal', 'is_explicit', 'created_at', 'published_at', 'duration', 'is_downloadable', 'genre_id', 'track_type_id', 'cover_id', 'album_id');
		}

		protected $table = 'tracks';

		public function genre() {
			return $this->belongsTo('Entities\Genre');
		}

		public function cover() {
			return $this->belongsTo('Entities\Image');
		}

		public function showSongs() {
			return $this->belongsToMany('Entities\ShowSong');
		}

		public function user() {
			return $this->belongsTo('Entities\User');
		}

		public function album() {
			return $this->belongsTo('Entities\Album');
		}

		public function getYear() {
			return date('Y', strtotime($this->release_date));
		}

		public function getReleaseDate() {
			if($this->attributes['released_at'] !== NULL)
				return $this->attributes['released_at'];

			if ($this->attributes['published_at'] !== NULL)
				return Str::limit($this->$this->attributes['published_at'], 10, '');

			return Str::limit($this->attributes['created_at'], 10, '');
		}

		public function ensureDirectoryExists() {
			$destination = $this->getDirectory();

			if (!is_dir($destination))
				mkdir($destination, 755);
		}

		public function hasCover() {
			return $this->cover_id != null;
		}

		public function isPublished() {
			return $this->published_at != null && $this->deleted_at == null;
		}

		public function getCoverUrl($type = Image::NORMAL) {
			if (!$this->hasCover()) {
				if ($this->album_id != null)
					return $this->album->getCoverUrl($type);

				return $this->user->getAvatarUrl($type);
			}

			return $this->cover->getUrl($type);
		}

		public function getDirectory() {
			$dir = (string) ( floor( $this->id / 100 ) * 100 );
			return \Config::get('app.files_directory') . '/tracks/' . $dir;
		}

		public function getDates() {
			return ['created_at', 'deleted_at', 'published_at', 'released_at'];
		}

		public function getFilenameFor($format) {
			if (!isset(self::$Formats[$format]))
				throw new Exception("$format is not a valid format!");

			$format = self::$Formats[$format];
			return "{$this->id}.{$format['extension']}";
		}

		public function getFileFor($format) {
			if (!isset(self::$Formats[$format]))
				throw new Exception("$format is not a valid format!");

			$format = self::$Formats[$format];
			return "{$this->getDirectory()}/{$this->id}.{$format['extension']}";
		}

		public function updateTags() {
			foreach (self::$Formats as $format => $data) {
				$this->{$data['tag_method']}($format);
			}
		}

		/** @noinspection PhpUnusedPrivateMethodInspection */
		private function updateTagsWithAtomicParsley($format) {
			$command = 'AtomicParsley "' . $this->getFileFor($format) . '" ';
			$command .= '--title ' . escapeshellarg($this->title) . ' ';
			$command .= '--artist ' . escapeshellarg($this->user->display_name) . ' ';
			$command .= '--year "' . $this->year . '" ';
			$command .= '--genre ' . escapeshellarg($this->genre != null ? $this->genre->title : '') . ' ';
			$command .= '--copyright ' . escapeshellarg('© '.$this->year.' '.$this->user->display_name).' ';
			$command .= '--comment "' . 'Downloaded from: https://pony.fm/' . '" ';
			$command .= '--encodingTool "' . 'Pony.fm' . '" ';
			$command .= '--encodedBy "' . 'Pony.fm - https://pony.fm/' . '" ';

			if ($this->album_id !== NULL) {
				$command .= '--album ' . escapeshellarg($this->album->title) . ' ';
				$command .= '--tracknum ' . $this->track_number . ' ';
			}

			if ($this->cover !== NULL) {
				$command .= '--artwork ' . $this->getCoverUrl() . ' ';
			}

			$command .= '--overWrite';

			External::execute($command);
		}

		/** @noinspection PhpUnusedPrivateMethodInspection */
		private function updateTagsWithGetId3($format) {
			require_once(app_path() . '/library/getid3/getid3.php');
			require_once(app_path() . '/library/getid3/write.php');
			$tagWriter = new getid3_writetags;

			$tagWriter->overwrite_tags = true;
			$tagWriter->tag_encoding   = 'UTF-8';
			$tagWriter->remove_other_tags = true;

			$tagWriter->tag_data = [
				'title'   				=> [$this->title],
				'artist'  				=> [$this->user->display_name],
				'year'    				=> ['' . $this->year],
				'genre'   				=> [$this->genre != null ? $this->genre->title : ''],
				'comment' 				=> ['Downloaded from: https://pony.fm/'],
				'copyright'				=> ['© ' . $this->year . ' ' . $this->user->display_name],
				'publisher'  			=> ['Pony.fm - https://pony.fm/'],
				'encoded_by' 			=> ['https://pony.fm/'],
//				'url_artist'			=> [$this->user->url],
//				'url_source'			=> [$this->url],
//				'url_file'				=> [$this->url],
				'url_publisher'			=> ['https://pony.fm/']
			];

			if ($this->album_id !== NULL) {
				$tagWriter->tag_data['album']	= [$this->album->title];
				$tagWriter->tag_data['track']	= [$this->track_number];
			}

			if ($format == 'MP3' && $this->cover_id != NULL && is_file($this->cover->file)) {
				$tagWriter->tag_data['attached_picture'][0] = [
					'data'			=>	file_get_contents($this->cover->file),
					'picturetypeid'	=>	2,
					'description'	=>	'cover',
					'mime'			=>	'image/png'
				];
			}

			$tagWriter->filename = $this->getFileFor($format);
			$tagWriter->tagformats = [self::$Formats[$format]['tag_format']];

			if ($tagWriter->WriteTags()) {
				if (!empty($tagWriter->warnings)) {
					Log::warning('There were some warnings:<br />' . implode('<br /><br />', $tagWriter->warnings));
				}
			} else {
				Log::error('Failed to write tags!<br />' . implode('<br /><br />', $tagWriter->errors));
			}
		}
	}