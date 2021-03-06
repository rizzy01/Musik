<?php

/**
 * Description of artists
 *
 * @author Hemant Mann
 */
use Framework\RequestMethods as RequestMethods;
use Framework\Registry as Registry;
use LastFm\Src\Geo as Geo;
use LastFm\Src\Artist as Artst;
use LastFm\Src\Track as Trck;
use Framework\ArrayMethods as ArrayMethods;
use LastFm\Src\Util as Util;

class Tracks extends Admin {
	
	public function top($page = 1) {
		$view = $this->getActionView();
		if (is_numeric($page) === FALSE) { self::redirect("/tracks/top/1"); }
		
		$page = (int) $page; $pageMax = 50;
		if ($page > $pageMax) {
            $page = $pageMax;
        } elseif ($page === 0) {
            $page = 1;
        }
		$session = Registry::get("session");

		if (!$session->get("country")) {
            $ip = $_SERVER['REMOTE_ADDR'];
            $country = $this->getCountry($ip);
            $session->set("country", $country);
        }

        $seo = $this->seoOptimize();
        $this->seo(array(
            "title" => "Musik | Top Tracks - " . $session->get("country"),
            "keywords" => $seo["keywords"] . "Top tracks of ". $session->get("country"),
            "description" => $seo["description"],
            "view" => $this->getLayoutView()
        ));

		if (!$session->get('Tracks\Top:$tracks') || $session->get('Tracks\Top:page') != $page) {
			try {
				$topTracks = Geo::getTopTracks($session->get("country"), $page);

				$tracks = array();
				foreach ($topTracks as $track) {
					$tracks[] = array(
						"name" => $track->getName(),
						"mbid" => $track->getMbid(),
						"image" => $track->getImage(4),
						"artist" => $track->getArtist()->getName()
					);
				}
				$tracks = ArrayMethods::toObject($tracks);

				$session->set('Tracks\Top:page', $page);
				$session->set('Tracks\Top:$tracks', $tracks);

			} catch (\Exception $e) {
				self::redirect("/404");
			}	
		}

		$view->set("title", "Top Tracks - ". $session->get("country"));
		$view->set("pagination", $this->setPagination("/tracks/top/", $page, 1, $pageMax));
		$view->set("tracks", $session->get('Tracks\Top:$tracks'));
		$view->set("count", array(1,2,3,4,5));
	}

	public function view($song, $artist) {
		$view = $this->getActionView();
		$session = Registry::get("session");

		if (empty($artist) || empty($song)) {
		    self::redirect("/404");
		}

		$seo = $this->seoOptimize();
        $this->seo(array(
            "title" => "Musik | View Track - " . $song,
            "keywords" => $seo["keywords"] . "Listen to $song",
            "description" => $seo["description"],
            "view" => $this->getLayoutView()
        ));

		if ($session->get('Tracks\View:track') != $song || $session->get('Tracks\View:artist') != $artist) {
			try {

				$track = Trck::getInfo($artist, $song);
				/*** Track Info ***/
				$t = array();
				$t["name"] = $track->getName();
				$t["duration"] = $track->getDuration();
				$t["mbid"] = $track->getMbid();
				$t["artist"] = $track->getArtist()->getName();
				$t["artistMbid"] = $track->getArtist()->getMbid();
				$t["playCount"] = $track->getPlayCount();
				$wiki = $track->getWiki();
				$t["wiki"] = $wiki["summary"];

				/*** Track - Album ***/
				$album = $track->getAlbum();
				$t["album"] = Util::toString($album["title"]);
				$t["image"] = Util::toString($album["image"][0]);

				/*** Track - Tags ***/
				$tags = $track->getTrackTopTags();
				$t["tags"] = array();
				foreach ($tags as $tag) {
				    $t["tags"][] = array(
				        "name" => $tag->getName()
				    );
				}
				$t = ArrayMethods::toObject($t);

				/*** Track - Artist => TopTracks ***/
				$topTracks = Artst::getTopTracks($t->artist);
				$tracks = array();
				foreach ($topTracks as $track) {
				    $tracks[] = array(
				        "name" => $track->getName(),
				        "playCount" => $track->getPlayCount(),
				        "mbid" => $track->getMbid()
				    );
				}
				$tracks = ArrayMethods::toObject($tracks);

				// Also find similar tracks
				$similarTracks = Trck::getSimilar($t->artist, $t->name);
				$similar = array();
				foreach ($similarTracks as $track) {
				    $similar[] = array(
				        "name" => $track->getName(),
				        "mbid" => $track->getMbid(),
				        "playCount" => $track->getPlayCount(),
				        "artist" => $track->getArtist()->getName(),
				        "thumbnail" => $track->getImage(2)
				    );
				}
				$similar = ArrayMethods::toObject($similar);

				$session->set('Tracks\View:track', $song);
				$session->set('Tracks\View:artist', $artist);
				$session->set('Tracks\View:$trackInfo', $t);
				$session->set('Tracks\View:$topTracks', $tracks);
				$session->set('Tracks\View:$similarTracks', $similar);
			} catch (\Exception $e) {
				$session->erase('Tracks\View:track');
				$session->erase('Tracks\View:artist');
				$session->erase('Tracks\View:$trackInfo');
				$session->erase('Tracks\View:$topTracks');
				$session->erase('Tracks\View:$similarTracks');

				$session->set('Tracks\View:$notFound', true);
				self::redirect("/artists/view/{$artist}");
			}
		}
		
		$view->set('track', $session->get('Tracks\View:$trackInfo'));
		$view->set('tracks', $session->get('Tracks\View:$topTracks'));
		$view->set('similar', $session->get('Tracks\View:$similarTracks'));
	}

	/**
	 * @before _secure, changeLayout
	 */
	public function downloads() {
		$this->seo(array("title" => "View Downloads", "keywords" => "admin", "description" => "admin", "view" => $this->getLayoutView()));
        $view = $this->getActionView();

        $page = RequestMethods::get("page", 1);
        $limit = RequestMethods::get("limit", 10);
        $orderBy = RequestMethods::get("orderBy", "count");
        $downloads = \Download::all(array(), array("strack_id", "count", "id", "modified"), $orderBy, "desc", $limit, $page);
        $database = Registry::get("database");
        $total = $database->query()->from("downloads", array("SUM(count)" => "songs"))->all();
        $count = \Download::count();

        // find the directory size
        exec('du -h '. APP_PATH.'/application/libraries/YTDownloader/downloads', $output, $return);
        if ($return == 0) {
        	$output = array_pop($output);
        	$output = explode("/", $output);
        	$size = array_shift($output);
        	$size = trim($size);
        } else {
        	$size = 'Failed to get size';
        }

        $results = array();
        foreach ($downloads as $d) {
        	$track = \SavedTrack::first(array("id = ?" => $d->strack_id), array("track", "artist", "yid"));
        	$results[] = array(
        		"track" => $track->track,
        		"artist" => $track->artist,
        		"count" => $d->count,
        		"yid" => $track->yid,
        		"strack_id" => $d->strack_id,
        		"last" => $d->modified
        	);
        }
        $results = ArrayMethods::toObject($results);

        $view->set("size", $size);
        $view->set("results", $results);
		$view->set("total", $total[0]["songs"]);
		$view->set("count", $count);
		$view->set("limit", $limit);
        $view->set("page", (int) $page);  
	}
}