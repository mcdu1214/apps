<?php

/**
 * ownCloud - Activities App
 *
 * @author Frank Karlitschek, Joas Schilling
 * @copyright 2013 Frank Karlitschek frank@owncloud.org
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Activity;

/**
 * @brief The class to handle the filesystem hooks
 */
class Hooks {
	public static $createhookfired = false;
	public static $createhookfile = '';

	/**
	 * @brief Registers the filesystem hooks for basic filesystem operations.
	 * All other events has to be triggered by the apps.
	 */
	public static function register() {
		//Listen to create file signal
		\OCP\Util::connectHook('OC_Filesystem', 'post_create', "OCA\Activity\Hooks", "file_create");

		//Listen to rename file signal
		\OCP\Util::connectHook('OC_Filesystem', 'post_rename', "OCA\Activity\Hooks", "file_rename");

		//Listen to delete file signal
		\OCP\Util::connectHook('OC_Filesystem', 'delete', "OCA\Activity\Hooks", "file_delete");

		//Listen to write file signal
		\OCP\Util::connectHook('OC_Filesystem', 'post_write', "OCA\Activity\Hooks", "file_write");

		//Listen to share signal
		\OCP\Util::connectHook('OCP\Share', 'post_shared', "OCA\Activity\Hooks", "share");

		// hooking up the activity manager
		if (property_exists('OC', 'server')) {
			if (method_exists(\OC::$server, 'getActivityManager')) {
				$am = \OC::$server->getActivityManager();
				$am->registerConsumer(function() {
					return new Consumer();
				});
			}
		}
	}

	/**
	 * @brief Store the write hook events
	 * @param array $params The hook params
	 */
	public static function file_write($params) {
		if ( self::$createhookfired ) {
			$params['path'] = self::$createhookfile;
			self::$createhookfired = false;
			self::$createhookfile = '';

			$type_others = 8;
			$type_self = 3;
			$subject = '%s created';
			$subject_others = '%s created by %s';
		} else {
			$type_others = 6;
			$type_self = 1;
			$subject = '%s changed';
			$subject_others = '%s changed by %s';
		}

		$link = \OCP\Util::linkToAbsolute('files', 'index.php', array('dir' => dirname($params['path'])));
		Data::send('files', $subject, substr($params['path'], 1), '', array(), $params['path'], $link, \OCP\User::getUser(), $type_self);

		// Add Activity for the owner of the folder shared
		$uidOwner = \OC\Files\Filesystem::getOwner($params['path']);
		if (substr($params['path'], 0, 8) == '/Shared/') {
			$realfile = substr($params['path'],7);
			$link = \OCP\Util::linkToAbsolute('files', 'index.php', array('dir' => dirname($realfile)));
			Data::send('files', $subject_others, array($realfile, \OCP\User::getUser()), '', array(), $realfile, $link, $uidOwner, $type_others, Data::PRIORITY_HIGH);
		}

		// Add Activity for users that got the folder shared
		$affected_users = \OCP\Share::getUsersSharingFile($params['path'], $uidOwner);
		if (!empty($affected_users['users'])) {
			foreach ($affected_users['users'] as $affected_user) {
				$realfile = '/Shared' . $params['path'];
				$link = \OCP\Util::linkToAbsolute('files', 'index.php', array('dir' => dirname($realfile)));
				Data::send('files', $subject_others, array($realfile, \OCP\User::getUser()), '', array(), $realfile, $link, $affected_user, $type_others, Data::PRIORITY_HIGH);
			}
		}
	}

	/**
	 * @brief Store the delete hook events
	 * @param array $params The hook params
	 */
	public static function file_delete($params) {
		$link = \OCP\Util::linkToAbsolute('files', 'index.php', array('dir' => dirname($params['path'])));
		$subject = '%s deleted';
		Data::send('files', $subject, substr($params['path'], 1), '', array(), $params['path'], $link, \OCP\User::getUser(), 2);

		$subject = '%s deleted by %s';

		// Add Activity for the owner of the folder shared
		$uidOwner = \OC\Files\Filesystem::getOwner($params['path']);
		if(substr($params['path'],0,8)=='/Shared/') {
			$realfile=substr($params['path'],7);
			$link = \OCP\Util::linkToAbsolute('files', 'index.php', array('dir' => dirname($realfile)));
			Data::send('files', $subject, array($realfile,\OCP\User::getUser()), '', array(), $realfile, $link, $uidOwner, 7, Data::PRIORITY_HIGH);
		}

		// Add Activity for users that got the folder shared
		$affected_users = \OCP\Share::getUsersSharingFile($params['path'], $uidOwner);
		if (!empty($affected_users['users'])) {
			foreach ($affected_users['users'] as $affected_user) {
				$realfile = '/Shared' . $params['path'];
				$link = \OCP\Util::linkToAbsolute('files', 'index.php', array('dir' => dirname($realfile)));
				Data::send('files', $subject, array($realfile, \OCP\User::getUser()), '', array(), $realfile, $link, $affected_user, 7, Data::PRIORITY_HIGH);
			}
		}
	}

	/**
	 * @brief Store the rename hook events
	 * @param array $params The hook params
	 */
	public static function file_rename($params) {
		$link = \OCP\Util::linkToAbsolute('files', 'index.php', array('dir' => dirname($params['newpath'])));
		$subject = '%1$s renamed to %2$s';
		Data::send('files', $subject, array($params['oldpath'], $params['newpath']), '', array(), $params['path'], $link, \OCP\User::getUser(), 1);

		$subject = '%1$s renamed to %2$s by %3$s';

		// Add Activity for the owner of the folder shared
		$uidOwner = \OC\Files\Filesystem::getOwner(dirname($params['newpath']));
		if (substr($params['newpath'], 0, 8) == '/Shared/') {
			$newfile = substr($params['newpath'], 7);
			$oldpath = substr($params['oldpath'], 7);
			$link = \OCP\Util::linkToAbsolute('files', 'index.php', array('dir' => dirname($newfile)));
			Data::send('files', $subject, array($oldpath, $newfile, \OCP\User::getUser()), '', array(), $newfile, $link, $uidOwner, 6, Data::PRIORITY_HIGH);
		}

		// Add Activity for users that got the folder shared
		$affected_users = \OCP\Share::getUsersSharingFile($params['newpath'], $uidOwner);
		if (!empty($affected_users['users'])) {
			foreach ($affected_users['users'] as $affected_user) {
				$newfile = '/Shared' . $params['newpath'];
				$oldpath = '/Shared' . $params['oldpath'];
				$link = \OCP\Util::linkToAbsolute('files', 'index.php', array('dir' => dirname($newfile)));
				Data::send('files', $subject, array($oldpath, $newfile, \OCP\User::getUser()), '', array(), $newfile, $link, $affected_user, 6, Data::PRIORITY_HIGH);
			}
		}
	}

	/**
	 * @brief Store the create hook events
	 * @param array $params The hook params
	 */
	public static function file_create($params) {
		// remember the create event for later consumption
		self::$createhookfired = true;
		self::$createhookfile = $params['path'];
	}

	/**
	 * @brief Store the share events
	 * @param array $params The hook params
	 */
	public static function share($params) {

		if ($params['itemType'] === 'file' || $params['itemType'] === 'folder') {
			$link = \OCP\Util::linkToAbsolute('files', 'index.php', array('dir' => dirname($params['fileTarget'])));
			$link_shared = \OCP\Util::linkToAbsolute('files', 'index.php', array('dir' => dirname('/Shared/'.$params['fileTarget'])));

			$sharedFrom = \OCP\User::getUser();
			$shareWith = $params['shareWith'];

			if(!empty($shareWith)) {
				$subject = 'You shared %s with %s';
				Data::send('files', $subject, array(substr($params['fileTarget'], 1), $shareWith), '', array(), $params['fileTarget'], $link, \OCP\User::getUser(), 4, Data::PRIORITY_MEDIUM );

				$subject = '%s shared %s with you';
				Data::send('files', $subject, array($sharedFrom, substr('/Shared'.$params['fileTarget'], 1)), '', array(), '/Shared/'.$params['fileTarget'], $link_shared, $shareWith, 5, Data::PRIORITY_MEDIUM);
			} else {
				$subject = 'You shared %s';
				Data::send('files', $subject, array(substr($params['fileTarget'], 1)), '', array(), $params['fileTarget'], $link, \OCP\User::getUser(), 4, Data::PRIORITY_MEDIUM );
			}
		}
	}
}
