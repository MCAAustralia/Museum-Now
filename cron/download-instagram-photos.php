<?php

/**
 * This file, executed in 10 minute intervals as part of a cron job,
 * downloads the photos and metadata from Instagram for display on
 * digital signage and for any other public facing feeds on the
 * website. Photos are cached on the server due to the fact that
 * the digital signage may not be able to acess the Internet and
 * therefore cannot interact directly with the Instagram API.
 *
 * Museum Now is a brain-child of the Museum of Contemporary Art Australia,
 * made with much love by Tim Wray and is based on the work of MCA Now,
 * a product produced by Rory McKay.
 *
 * Contact: timwray.mail@gmail.com; rorymckay@gmail.com
 *
 */

require_once(realpath(dirname(__FILE__).'/../core/core.php'));

/**
 * Disable error reporting when run from a browser / Web API call
 * at least for production version of Museum Now
 * so that error outputs don't interfere with JSON response
 */
if (!is_running_from_command_line())
{
	error_reporting(0);
}

/**
 * Don't actually execute this script unless Museum Now is installed. 
 */
if (!museum_now_is_installed())
{
	exit();
}

/**
 * Increased maximum execution time to 5 minutes to allow for potentially long
 * operation of downloading photos from the Instagram stream. 
 */
ini_set('max_execution_time', 600);

/**
 * Download photos from Instagram
 */

$APIData = call_instagram_api(PHOTOS_FROM_OWN_FEED_API_ENDPOINT, get_metadata('instagram-access-token'));
$APIResponseCodeForPhotosFromOwnFeedCall = $APIData->meta->code;
$photosFromOwnFeed = $APIData->data;
$APIData = call_instagram_api(LIKED_PHOTOS_API_ENDPOINT, get_metadata('instagram-access-token'));
$APIResponseCodeForForLikedPhotos = $APIData->meta->code;
$likedPhotos = $APIData->data;

$photosFromOwnFeedAndLikedPhotos = array_merge($photosFromOwnFeed, $likedPhotos);
usort($photosFromOwnFeedAndLikedPhotos, 'instagram_photo_sort_by_created_time_desc');

// Only extract the number represented by the constant
// AMOUNT_OF_PHOTOS_TO_DOWNLOAD from the metadata, that
// number being the most recently uploaded photos posted
// to Instagram
$recentlyUploadedPhotosFromOwnFeedAndLikedPhotos = array_splice($photosFromOwnFeedAndLikedPhotos, 0, AMOUNT_OF_PHOTOS_TO_DOWNLOAD);

$photoMetadataToSave = array();
$userIDsAsKeysProfileImagesAsURLs = array();
$userProfileMetadataToSave = array();

$amountOfInstagramPhotosDownloaded = 0;
$amountOfUserProfileImgagesDownloaded = 0;

/**
 * Cache Instagram photo images into /store/cached/instagram-photos/
 */
foreach ($recentlyUploadedPhotosFromOwnFeedAndLikedPhotos as $photo)
{
	$userIDsAsKeysProfileImagesAsURLs[$photo->user->id] = $photo->user->profile_picture;
   $imgURL = INSTAGRAM_PHOTOS_DIR.'/'.get_photo_filename($photo, 'photo');
   $photoMetadataToSave[] = format_photo_data_object_for_cached_metadata_storage($photo);
   if (!file_exists($imgURL))
	{
		$imgData = file_get_contents_via_proxy($photo->images->standard_resolution->url, proxy_settings());
		$amountOfInstagramPhotosDownloaded++;
		file_put_contents($imgURL, $imgData);
		@chmod($imgURL, 0777);
	}
}

/**
 * Cache Instagram user profile images into /cached/profilephotos/
 */
foreach ($userIDsAsKeysProfileImagesAsURLs as $userID => $profileImageURL)
{
	$imgURL = INSTAGRAM_USERS_DIR.'/profilephoto_'.$userID.'.jpg';
	$userProfileMetadataToSave[] = (object) array('user_id' => $userID, 'image' => (object) array('src' => INSTAGRAM_USERS_DIR_RELATIVE_TO_DIGITALSIGN.'/profilephoto_'.$userID.'.jpg'));
	if (!file_exists($imgURL))
	{
		$imgData = file_get_contents_via_proxy($profileImageURL, proxy_settings());
		$amountOfUserProfileImgagesDownloaded++;
		file_put_contents($imgURL, $imgData);
		@chmod($imgURL, 0777);
	}
}

/**
 * Save $recentlyUploadedPhotosFromOwnFeedAndLikedPhotos as cached metadata at
 * /store/instagram-photos/metadata.json
 */
file_put_contents(INSTAGRAM_PHOTOS_METADATA_FILE, make_json_pretty(json_encode($photoMetadataToSave)));
@chmod(INSTAGRAM_PHOTOS_METADATA_FILE, 0777);

/**
 * Save $userIDsAsKeysCachedProfileImagesAsURLs as cached metadata at
 * /get/instagram-users.json
 */
file_put_contents(INSTAGRAM_USERS_METADATA_FILE, make_json_pretty(json_encode($userProfileMetadataToSave)));
@chmod(INSTAGRAM_USERS_METADATA_FILE, 0777);

if ($amountOfInstagramPhotosDownloaded > 0)
{
	if ($amountOfInstagramPhotosDownloaded == 1)
	{
		log_message("{$amountOfInstagramPhotosDownloaded} photo has been updated from the Instagram feed");
	}
	else if ($amountOfInstagramPhotosDownloaded > 1)
	{
		log_message("{$amountOfInstagramPhotosDownloaded} photos have been updated from the Instagram feed");
	}
}

/**
 * 'Cleans up' / removes any photos in the /store/cached directories that are not
 *  present in $photoMetadataToSave and $userProfileMetadataToSave. This is 
 *  to prevent the server getting clogged up with photos downloaded from 
 *  Instagram.
 */
$imageFilePathsForImagesCurrentlyDisplayed = array();
$imageFilePathsForProfilePhotosCurrentlyDisplayed = array();

foreach ($photoMetadataToSave as $photoMetadata)
{
	$imageFilePathsForImagesCurrentlyDisplayed[] = $photoMetadata->images->locally_stored->url;
}

foreach ($userProfileMetadataToSave as $userProfileMetadata)
{
	$imageFilePathsForProfilePhotosCurrentlyDisplayed[] = $userProfileMetadata->image->src;
}

$imageFileNamesToRetain = array_merge($imageFilePathsForImagesCurrentlyDisplayed, $imageFilePathsForProfilePhotosCurrentlyDisplayed);

// Need to convert relative paths in $imageFileNamesToRetain to full,
// absolute paths
$imageFileNamesToRetainAsFullPaths = array();
foreach ($imageFileNamesToRetain as $imageFileNameToRetain)
{
	$imageFileNamesToRetainAsFullPaths[] = str_replace(CACHED_DIR_RELATIVE_TO_DIGITALSIGN, CACHED_DIR, $imageFileNameToRetain);
}

$imageFilePathsInCacheDirectory = rglob(CACHED_DIR.'/*.jpg');
$imagesToDelete = array_diff($imageFilePathsInCacheDirectory, $imageFileNamesToRetainAsFullPaths);

foreach ($imagesToDelete as $imageToDelete)
{
	unlink($imageToDelete);
}

/**
 * If not calling from the console, then return JSON to indicate how well
 * the request was made. 
 */
if (!is_running_from_command_line())
{
	if ($APIResponseCodeForPhotosFromOwnFeedCall == 200 && $APIResponseCodeForForLikedPhotos == 200)
	{
		echo make_json_pretty(json_encode(array('instagram_api_status_response' => 200)));
	}
	else
	{
		echo make_json_pretty(json_encode(array('instagram_api_status_response' => 'error')));
	}
}

/**
 * Calls the Instagram API for a given end-point 
 * and access token
 * 
 * @param string $endPoint The API end point to call.
 *
 * @param string $accessToken The signed and authenticated
 * API access token.
 *
 * @return array The raw data from the API, returned as an
 * associative array.
 *
 */
function call_instagram_api($endpoint, $accessToken)
{
   try 
   {
		return json_decode(file_get_contents_via_proxy($endpoint."?access_token=".$accessToken, proxy_settings()));
   }
   catch (Exception $e)
   {
      log_message(AS_ERROR, "Error accessing Instagram API: Please ensure that this server can access the Internet", TRUE);
   }
}

/**
 * Custom sort function for sorting Instagram photos in descending
 * order of date created - most recent photos are shown first.
 */
function instagram_photo_sort_by_created_time_desc($photo1, $photo2)
{
   if ($photo1->created_time == $photo2->created_time)
   {
      return 0;
   }
   else if ($photo1->created_time < $photo2->created_time)
   {
      return 1;
   }
   else if ($photo1->created_time > $photo2->created_time)
   {
      return -1;
   }
}

/**
 * Formats photo metadata from the Instagram API for local storage. The data
 * we are going to store locally for the Instagram photo object is identical
 * to the data as returned from the API, except that we replace image URL
 * references with our own, cached references on the server side.
 * 
 * In doing so, we remove the 'images' property from each photo object and 
 * replace it with our own 'image' URL which refers to our cached copy on the
 * server. Likewise, the 'profile_picture' property - which is a sub-property
 * of 'user', has its URL replaced with our own, cached copy.
 * 
 * @param stdClass $photoDataObject An object representation of a photo
 * from the Instagram API.
 * 
 * @return array The modified photo data object, to be stored locally in
 * /store/instagram-photos/metadata.json
 */
function format_photo_data_object_for_cached_metadata_storage($photoDataObject)
{
	// Clone $photoDataObject
	$photoDataObjectCloned = unserialize(serialize($photoDataObject));
	$imagePath = INSTAGRAM_PHOTOS_DIR_RELATIVE_TO_DIGITALSIGN.'/'.get_photo_filename($photoDataObject, 'photo');
	$photoDataObjectCloned->images->locally_stored = (object) array('url' => $imagePath);
	$userID = $photoDataObjectCloned->user->id;
	$photoDataObjectCloned->user->profile_picture_locally_stored = INSTAGRAM_USERS_DIR_RELATIVE_TO_DIGITALSIGN.'/profilephoto_'.$userID.'.jpg';
	return $photoDataObjectCloned;
}

/**
 * Formats the filename of an Instagram photo saved locally on the server.
 * The photo is timestampped based on its upload date within the Instagram
 * API.
 * @param stdClass $photoDataObject An object representation of a photo
 * from the Instagram API.
 * 
 * @param string $prefix An optional prefix to prepend to the file.
 * 
 * @return string The filename for the photo object. 
 */
function get_photo_filename($photoDataObject, $prefix = '')
{	
   $photoFileName = '';
   if (!empty($prefix))
   {
      $photoFileName = $prefix.'_';
   }
   $photoFileName .= $photoDataObject->created_time.'.jpg';
   return $photoFileName; 
}

?>