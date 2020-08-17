<?php
/**
 * @author Semih Serhat Karakaya <karakayasemi@itu.edu.tr>
 * @author Piotr Mrowczynski <piotr@owncloud.com>
 *
 * @copyright Copyright (c) 2019, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\Files\Tests\Command;

use OC\Encryption\Manager;
use OC\Share20\ProviderFactory;
use OC\User\NoUserException;
use OCA\Files\Command\TransferOwnership;
use OCP\Files\Folder;
use OCP\Files\Mount\IMountManager;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share;
use OCP\Share\IShare;
use OCP\Share\IManager;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Tester\CommandTester;
use Test\TestCase;
use Test\Traits\UserTrait;

/**
 * Class TransferOwnershipTest
 *
 * @group DB
 *
 * @package OCA\Files\Tests\Command
 */
class TransferOwnershipTest extends TestCase {
	use UserTrait;

	/**
	 * @var IUserManager
	 */
	private $userManager;

	/**
	 * @var IManager
	 */
	private $shareManager;

	/**
	 * @var IMountManager
	 */
	private $mountManager;
	/** @var IRootFolder */
	private $rootFolder;

	/**
	 * @var Manager | MockObject
	 */
	private $encryptionManager;

	/**
	 * @var ProviderFactory
	 */
	private $providerFactory;

	/**
	 * @var IUser
	 */
	private $shareSender;

	/**
	 * @var IUser
	 */
	private $sourceUser;

	/**
	 * @var IUser
	 */
	private $targetUser;

	/**
	 * @var CommandTester
	 */
	private $commandTester;

	protected function setup(): void {
		parent::setUp();
		$this->userManager = \OC::$server->getUserManager();
		$this->shareManager = \OC::$server->getShareManager();
		$this->mountManager = \OC::$server->getMountManager();
		$this->rootFolder = \OC::$server->getRootFolder();
		$this->encryptionManager = $this->createMock(Manager::class);
		$this->providerFactory = new ProviderFactory(\OC::$server);

		$this->shareSender = $this->createUser('share-sender');
		$this->sourceUser = $this->createUser('source-user');
		$this->targetUser = $this->createUser('target-user');
		$this->unloggedUser = $this->createUser('unlogged-user');
		$this->shareReceiver = $this->createUser('share-receiver');
		$this->loginAsUser('share-sender');
		$this->logout();
		$this->loginAsUser('source-user');
		$this->logout();
		$this->loginAsUser('target-user');
		$this->logout();
		$this->loginAsUser('share-receiver');
		$this->logout();

		$this->createTestFilesForSourceUser();

		$command = new TransferOwnership(
			$this->userManager,
			$this->shareManager,
			$this->mountManager,
			$this->rootFolder,
			$this->encryptionManager,
			$this->providerFactory
		);
		$this->commandTester = new CommandTester($command);
	}
	protected function tearDown(): void {
		$this->tearDownUserTrait();
		$this->shareManager->userDeleted($this->shareSender->getUID());
		$this->shareManager->userDeleted($this->sourceUser->getUID());
		$this->shareManager->userDeleted($this->targetUser->getUID());
		$this->shareManager->userDeleted($this->unloggedUser->getUID());
		$this->shareManager->userDeleted($this->shareReceiver->getUID());
		parent::tearDown();
	}

	/**
	 * Creates files and folder for source-user as the following tree:
	 *
	 * ├── file_in_user_root_folder
	 * ├── shared_file_to_source_user (shared by share-sender)
	 * ├── reshare_file_to_source_user (shared by share-sender and reshared by source-user to share-receiver)
	 * ├── shared_file_in_user_root_folder (shared with share-receiver)
	 * ├── transfer
	 * │   ├── shared_file (shared with share-receiver)
	 * │   ├── test_file1
	 * │   ├── test_file2
	 * │   ├── sub_folder
	 * │       ├── shared_file_in_sub_folder (shared with share-receiver)
	 */
	private function createTestFilesForSourceUser() {
		$sharerFolder = \OC::$server->getUserFolder($this->shareSender->getUID());

		$file = $sharerFolder->newFile('shared_file_to_source_user');
		$file->putContent('A test file');
		$share = $this->shareManager->newShare();
		$share->setNode($file)
			->setSharedBy('share-sender')
			->setSharedWith('source-user')
			->setShareType(Share::SHARE_TYPE_USER)
			->setPermissions(19);
		$this->shareManager->createShare($share);

		$file = $sharerFolder->newFile('reshare_file_to_source_user');
		$file->putContent('A test file');
		$share = $this->shareManager->newShare();
		$share->setNode($file)
			->setSharedBy('share-sender')
			->setSharedWith('source-user')
			->setShareType(Share::SHARE_TYPE_USER)
			->setPermissions(19);
		$this->shareManager->createShare($share);

		$sourceUserFolder = \OC::$server->getUserFolder($this->sourceUser->getUID());

		$sourceUserFolder->newFile('file_in_user_root_folder');
		$file = $sourceUserFolder->newFile('shared_file_in_user_root_folder');
		$share = $this->shareManager->newShare();
		$share->setNode($file)
			->setSharedBy('source-user')
			->setSharedWith('share-receiver')
			->setShareType(Share::SHARE_TYPE_USER)
			->setPermissions(19);
		$this->shareManager->createShare($share);

		$sourceUserFolder->newFolder('transfer');
		$sourceUserFolder->newFile('transfer/test_file1');
		$sourceUserFolder->newFile('transfer/test_file2');
		$file = $sourceUserFolder->newFile('transfer/shared_file');
		$share = $this->shareManager->newShare();
		$share->setNode($file)
			->setSharedBy('source-user')
			->setSharedWith('share-receiver')
			->setShareType(Share::SHARE_TYPE_USER)
			->setPermissions(19);
		$this->shareManager->createShare($share);

		$sourceUserFolder->newFolder('transfer/sub_folder');
		$file = $sourceUserFolder->newFile('transfer/sub_folder/shared_file_in_sub_folder');
		$share = $this->shareManager->newShare();
		$share->setNode($file)
			->setSharedBy('source-user')
			->setSharedWith('share-receiver')
			->setShareType(Share::SHARE_TYPE_USER)
			->setPermissions(19);
		$this->shareManager->createShare($share);

		$subFolder = $sourceUserFolder->get('transfer/sub_folder');
		$share = $this->shareManager->newShare();
		$share->setNode($subFolder)
			->setSharedBy('source-user')
			->setSharedWith('share-receiver')
			->setShareType(Share::SHARE_TYPE_USER)
			->setPermissions(19);
		$this->shareManager->createShare($share);

		$file = $sourceUserFolder->get('reshare_file_to_source_user');
		$share = $this->shareManager->newShare();
		$share->setNode($file)
			->setSharedBy('source-user')
			->setSharedWith('share-receiver')
			->setShareType(Share::SHARE_TYPE_USER)
			->setPermissions(19);
		$this->shareManager->createShare($share);
	}

	public function testTransferAllFiles() {
		$this->encryptionManager->method('isReadyForUser')->willReturn(true);
		$input = [
			'source-user' => $this->sourceUser->getUID(),
			'destination-user' => $this->targetUser->getUID()
		];
		$this->commandTester->execute($input);
		$output = $this->commandTester->getDisplay();

		$this->assertStringContainsString('Transferring files to target-user', $output);

		// check nodes count

		$sourceUserFolder = \OC::$server->getUserFolder($this->sourceUser->getUID());
		$this->assertTrue($sourceUserFolder->nodeExists('shared_file_to_source_user'));
		$this->assertTrue($sourceUserFolder->nodeExists('reshare_file_to_source_user'));
		$this->assertFalse($sourceUserFolder->nodeExists('file_in_user_root_folder'));
		$this->assertFalse($sourceUserFolder->nodeExists('shared_file_in_user_root_folder'));
		$this->assertFalse($sourceUserFolder->nodeExists('transfer'));

		/** @var $transferedFolder Folder */
		$targetUserFolder = \OC::$server->getUserFolder($this->targetUser->getUID());
		$targetUserFolderContents = $targetUserFolder->getDirectoryListing();
		$this->assertCount(1, $targetUserFolderContents);
		$transferedFolder = $targetUserFolderContents[0];
		$this->assertFalse($transferedFolder->nodeExists('shared_file_to_source_user'));
		$this->assertFalse($transferedFolder->nodeExists('reshare_file_to_source_user'));
		$this->assertTrue($transferedFolder->nodeExists('file_in_user_root_folder'));
		$this->assertTrue($transferedFolder->nodeExists('shared_file_in_user_root_folder'));
		$this->assertTrue($transferedFolder->nodeExists('transfer/shared_file'));
		$this->assertTrue($transferedFolder->nodeExists('transfer/test_file1'));
		$this->assertTrue($transferedFolder->nodeExists('transfer/test_file2'));
		$this->assertTrue($transferedFolder->nodeExists('transfer/sub_folder/'));
		$this->assertTrue($transferedFolder->nodeExists('transfer/sub_folder/shared_file_in_sub_folder'));

		// check shares counts
		$sourceShares = $this->shareManager->getSharesBy($this->sourceUser->getUID(), Share::SHARE_TYPE_USER);
		$targetShares = $this->shareManager->getSharesBy($this->targetUser->getUID(), Share::SHARE_TYPE_USER);
		$this->assertCount(1, $sourceShares);
		$this->assertCount(4, $targetShares);
	}

	public function folderPathProvider() {
		return [
			['transfer', 2, 3],
			['transfer/sub_folder', 3, 2]
		];
	}

	/**
	 * @dataProvider folderPathProvider
	 *
	 * @param string $path
	 * @param int $expectedSourceShareCount
	 * @param int $expectedTargetShareCount
	 */
	public function testTransferSpecificFolder($path, $expectedSourceShareCount, $expectedTargetShareCount) {
		$this->encryptionManager->method('isReadyForUser')->willReturn(true);
		$input = [
			'source-user' => $this->sourceUser->getUID(),
			'destination-user' => $this->targetUser->getUID(),
			'--path' => $path
		];
		$this->commandTester->execute($input);
		$output = $this->commandTester->getDisplay();

		$this->assertStringContainsString('Transferring files to target-user', $output);
		$sourceShares = $this->shareManager->getSharesBy($this->sourceUser->getUID(), Share::SHARE_TYPE_USER);
		$targetShares = $this->shareManager->getSharesBy($this->targetUser->getUID(), Share::SHARE_TYPE_USER);
		$this->assertCount($expectedSourceShareCount, $sourceShares);
		$this->assertCount($expectedTargetShareCount, $targetShares);
	}

	public function testTransferAllFilesToUnlogged() {
		$this->encryptionManager->method('isReadyForUser')->willReturn(true);
		$input = [
			'source-user' => $this->sourceUser->getUID(),
			'destination-user' => $this->unloggedUser->getUID(),
		];
		$this->commandTester->execute($input);
		$this->assertSame(0, $this->commandTester->getStatusCode());
		$output = $this->commandTester->getDisplay();

		$this->assertStringContainsString('Transferring files to unlogged-user', $output);
		$sourceShares = $this->shareManager->getSharesBy($this->sourceUser->getUID(), Share::SHARE_TYPE_USER);
		$targetShares = $this->shareManager->getSharesBy($this->unloggedUser->getUID(), Share::SHARE_TYPE_USER);
		$this->assertCount(1, $sourceShares);
		$this->assertCount(4, $targetShares);
	}

	public function transferSpecificFolderSharesExceptionProvider() {
		$notFoundException = new NotFoundException();
		$noUserException = new NoUserException();
		return [
			[
				$notFoundException,
				['yes'],
				[],
				['There are user shares that cannot be transferred. Do you want to continue with the transfer and skip these shares?']
			],
			[
				$noUserException,
				['yes'],
				[],
				['There are user shares that cannot be transferred. Do you want to continue with the transfer and skip these shares?']
			],
			[
				$noUserException,
				['no'],
				[],
				['There are user shares that cannot be transferred. Do you want to continue with the transfer and skip these shares?', 'Execution terminated']
			],
			[
				$noUserException,
				[],
				['--accept-skipped-shares' => ' '],
				[]
			],
		];
	}

	/**
	 * @dataProvider transferSpecificFolderSharesExceptionProvider
	 *
	 * @param NotFoundException|NoUserException $exception
	 * @param string[] $inputs
	 * @param string[] $inputOptions
	 * @param string[] $expectedOutputs
	 */
	public function testTransferSpecificFolderSharesException($exception, $inputs, $inputOptions, $expectedOutputs) {
		$this->encryptionManager->method('isReadyForUser')->willReturn(true);

		$shareNode = $this->createMock(Folder::class);
		$shareNode->method('getPath')->willReturn('/source-user/files/transfer');
		$share = $this->createMock(IShare::class);
		$share->method('getId')->willReturn(1);
		$share->method('getNode')->willThrowException($exception);

		$shareManager = $this->createMock(IManager::class);
		$shareManager->expects($this->at(0))->method('getSharesBy')->with('source-user', 1, null, true, 50, 0)->willReturn([$share]);

		$command = new TransferOwnership(
			$this->userManager,
			$shareManager,
			$this->mountManager,
			$this->rootFolder,
			$this->encryptionManager,
			$this->providerFactory
		);
		$command->setHelperSet(new HelperSet([new QuestionHelper()]));
		$commandTester = new CommandTester($command);
		$commandTester->setInputs($inputs);

		$input = [
			'source-user' => $this->sourceUser->getUID(),
			'destination-user' => $this->targetUser->getUID(),
			'--path' => 'transfer'
		];
		$input = \array_merge($input, $inputOptions);
		$commandTester->execute($input);
		$output = $commandTester->getDisplay();

		$this->assertStringContainsString('Share with id 1 points at deleted file, skipping', $output);
		foreach ($expectedOutputs as $expectedOutput) {
			$this->assertStringContainsString($expectedOutput, $output);
		}
	}
}
