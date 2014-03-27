<?php

/**
 * Import Tidypics images
 *
 * @package hypeJunction
 * @subpackage Gallery
 *
 * @author Ismayil Khayredinov <ismayil.khayredinov@gmail.com>
 * @copyright Copyright (c) 2011-2014, Ismayil Khayredinov
 * @license http://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2
 */

namespace hypeJunction\Gallery\Untidy;

use ElggBatch;
use ElggObject;
use ElggRiverItem;

const PLUGIN_ID = 'hypeGalleryUntidy';

// Register event handlers
elgg_register_event_handler('init', 'system', __NAMESPACE__ . '\\init');
elgg_register_event_handler('upgrade', 'system', __NAMESPACE__ . '\\import_albums');
elgg_register_event_handler('upgrade', 'system', __NAMESPACE__ . '\\import_images');
elgg_register_event_handler('upgrade', 'system', __NAMESPACE__ . '\\import_order');
elgg_register_event_handler('upgrade', 'system', __NAMESPACE__ . '\\import_batches');
elgg_register_event_handler('upgrade', 'system', __NAMESPACE__ . '\\import_tags');

function init() {

}

function import_albums() {

	if (!elgg_is_admin_logged_in()) {
		return true;
	}

	$dbprefix = elgg_get_config('dbprefix');

	$old_subtype_id = get_subtype_id('object', 'album');
	if (!$old_subtype_id) {
		return true;
	}

	$subtype_id = get_subtype_id('object', 'hjalbum');
	if (!$subtype_id) {
		$subtype_id = add_subtype('object', 'hjalbum', 'hypeJunction\\Gallery\\hjAlbum');
	}

	$query = "UPDATE {$dbprefix}entities SET subtype=$subtype_id WHERE type = 'object' AND subtype = $old_subtype_id";
	update_data($query);

	$query = "UPDATE {$dbprefix}river SET subtype = 'hjalbum', view = 'river/object/hjalbum/create' WHERE subtype = 'album' AND action_type='create'";
	update_data($query);

	$query = "UPDATE {$dbprefix}river SET subtype = 'hjalbum' WHERE subtype = 'album'";
	update_data($query);
}

function import_images() {

	if (!elgg_is_admin_logged_in()) {
		return true;
	}

	$dbprefix = elgg_get_config('dbprefix');

	$old_subtype_id = get_subtype_id('object', 'image');
	if (!$old_subtype_id) {
		return true;
	}

	$subtype_id = get_subtype_id('object', 'hjalbumimage');
	if (!$subtype_id) {
		$subtype_id = add_subtype('object', 'hjalbumimage', 'hypeJunction\\Gallery\\hjAlbumImage');
	}

	$query = "UPDATE {$dbprefix}entities SET subtype=$subtype_id WHERE type = 'object' AND subtype = $old_subtype_id";
	update_data($query);

	$query = "UPDATE {$dbprefix}river SET subtype = 'hjalbumimage' WHERE subtype = 'image'";
	update_data($query);
}

function import_order() {

	if (!elgg_is_admin_logged_in()) {
		return true;
	}

	$albums = new ElggBatch('elgg_get_entities_from_metadata', array(
		'types' => 'object',
		'subtypes' => 'hjalbum',
		'metadata_name_value_pairs' => array(
			'name' => 'orderedImages', 'value' => null, 'operand' => 'NOT NULL',
		),
		'limit' => 0
	));

	foreach ($albums as $album) {

		$order = unserialize($album->orderedImages);
		if (is_array($order)) {
			foreach ($order as $position => $guid) {
				create_metadata($guid, 'priority', $position, 'int', $album->owner_guid, ACCESS_PUBLIC);
			}
		}
		unset($album->orderedImages);
	}

	return true;
}

function import_batches() {

	if (!elgg_is_admin_logged_in()) {
		return true;
	}

	$batches = new ElggBatch('elgg_get_entities', array(
		'types' => 'object',
		'subtypes' => 'tidypics_batch',
		'limit' => 0
	));
	$batches->setIncrementOffset(false);

	foreach ($batches as $batch) {

		$rivers = elgg_get_river(array(
			'object_guids' => $batch->guid
		));

		if ($rivers) {

			foreach ($rivers as $river) {

				if (!$river instanceof ElggRiverItem) {
					continue;
				}

				$posted = $river->posted;

				$batch_images = elgg_get_entities_from_relationship(array(
					'types' => 'object',
					'subtypes' => 'hjalbumimage',
					'relationship' => 'belongs_to_batch',
					'relationship_guid' => $batch->guid,
					'inverse_relationship' => true,
					'limit' => 0,
				));

				$river_images = array();
				if ($batch_images) {
					foreach ($batch_images as $image) {
						$river_images[] = $image->guid;
						$image->posted = $posted;
					}
				}

				$album = $batch->getContainerEntity();

				create_metadata($album->guid, "river_$posted", serialize($river_images), '', $album->owner_guid, $album->access_id, true);

				$dbprefix = elgg_get_config('dbprefix');
				$query = "UPDATE {$dbprefix}river
			SET subtype='hjalbum', action_type='update', view='river/object/hjalbum/update', object_guid={$album->guid}
			WHERE id={$river->id}";
				update_data($query);
			}
		}

		$batch->delete();
	}

	return true;
}

function import_tags() {

	if (!elgg_is_admin_logged_in()) {
		return true;
	}

	$phototags = new ElggBatch('elgg_get_annotations', array(
		'annotation_names' => 'phototag',
		'limit' => 0
	));
	$phototags->setIncrementOffset(false);

	foreach ($phototags as $phototag) {

		$obj = unserialize($phototag->value);

		if (!$obj) {
			continue;
		}

		$type = $obj->type;

		$image = get_entity($phototag->entity_guid);

		$x1 = $x2 = $y1 = $y2 = 0;

		$master_width = 550;
		$natural = getimagesize($image->getIconURL('master'));
		$natural_width = $natural[0];

		$ratio = $natural_width / $master_width;

		$coords_str = $obj->coords;
		$coords_str = explode(',', $coords_str);
		foreach ($coords_str as $coord_str) {
			list($axis, $coord) = explode(':', $coord_str);
			$axis = str_replace('"', '', $axis);
			$coord = (int) str_replace('"', '', $coord);
			switch ($axis) {
				case 'x1' :
					$x1 = $coord * $ratio;
					break;
				case 'y1' :
					$y1 = $coord * $ratio;
					break;
				case 'width' :
					$width = $coord * $ratio;
					break;
				case 'height' :
					$height = $coord * $ratio;
					break;
			}
		}

		$x2 = $x1 + $width;
		$y2 = $y1 + $height;


		$user = false;
		$tagger = get_entity($phototag->owner_guid);

		if ($type == 'user') {
			$user = get_entity($obj->value);
		}

		$tag = new ElggObject();
		$tag->subtype = 'hjimagetag';
		$tag->owner_guid = ($user) ? ($user->guid) : $tagger->guid; // tagged user is owner, so can delete the tag
		$tag->container_guid = $image->guid; // image owner will be able to manage tags via container
		$tag->title = ($type == 'word') ? $obj->value : '';
		$tag->description = '';
		$tag->width = $width;
		$tag->height = $height;
		$tag->x1 = $x1;
		$tag->x2 = $x2;
		$tag->y1 = $y1;
		$tag->y2 = $y2;
		$tag->access_id = $phototag->access_id;

		if ($tag->save()) {

			$tags = string_to_tag_array($tag->title);
			if (count($tags)) {
				foreach ($tags as $t) {
					create_metadata($image->guid, 'tags', $t, '', $tagger->guid, $image->access_id, true);
				}
			}

			$rivers = elgg_get_river(array(
				'annotation_ids' => $phototag->id
			));

			if ($rivers) {
				foreach ($rivers as $river) {

					if (!$river instanceof ElggRiverItem) {
						continue;
					}

					$dbprefix = elgg_get_config('dbprefix');
					$query = "UPDATE {$dbprefix}river
							SET subtype='hjimagetag', action_type='stream:phototag', view='framework/river/stream/phototag', object_guid={$tag->guid}, annotation_id=-1
							WHERE id={$river->id}";
					update_data($query);
				}
			}
		}

		$phototag->delete();

		if ($user && check_entity_relationship($user->guid, 'phototag', $image->guid)) {
			remove_entity_relationship($user->guid, 'phototag', $image->guid);
		}
	}
}
