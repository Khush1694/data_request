<?php
/**
 * @copyright Copyright (c) 2018 Arthur Schiwon <blizzz@arthur-schiwon.de>
 *
 * @author Arthur Schiwon <blizzz@arthur-schiwon.de>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\DataRequest\Services;

use OCA\DataRequest\Exceptions\HintedRuntime;
use OCP\Defaults;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Util;

class Request {
	protected ?string $defaultLanguage = null;
	private IGroupManager $groupManager;
	private IMailer $mailer;
	private IFactory $l10nFactory;
	private IConfig $config;
	private IUser $requester;
	private IL10N $l;
	private Defaults $defaults;

	public function __construct(
		IGroupManager $groupManager,
		IMailer $mailer,
		IFactory $l10nFactory,
		IConfig $config,
		IUserSession $userSession,
		IL10N $l,
		Defaults $defaults
	) {
		$this->groupManager = $groupManager;
		$this->mailer = $mailer;
		$this->l10nFactory = $l10nFactory;
		$this->config = $config;
		$this->requester = $userSession->getUser();
		$this->l = $l;
		$this->defaults = $defaults;
	}

	public function sendExportRequest(): void {
		$this->sendRequest(function (IUser $r): IEMailTemplate {
			return $this->getExportTemplate($r);
		});
	}

	public function sendDeleteRequest(): void {
		$this->sendRequest(function (IUser $r): IEMailTemplate {
			return $this->getDeletionTemplate($r);
		});
	}

	protected function sendRequest(callable $templateGenerator): void {
		$admins = $this->getAdmins();

		$oneMailSent = false;
		foreach ($admins as $admin) {
			$template = $templateGenerator($admin);
			if ($this->craftEmailTo($admin, $template) === true) {
				$oneMailSent = true;
			}
		}
		if (!$oneMailSent) {
			throw new HintedRuntime(
				'No mail was sent successfully',
				$this->l->t('No administrator could have been contacted.')
			);
		}
	}

	protected function getDefaultLang(): string {
		if ($this->defaultLanguage === null) {
			$this->defaultLanguage = $this->config->getSystemValue('default_language', 'en');
		}
		return $this->defaultLanguage;
	}

	protected function craftEmailTo(IUser $admin, IEMailTemplate $template): bool {
		$senderAddress = Util::getDefaultEmailAddress('no-reply');
		$senderName = $this->defaults->getName();

		$adminEmail = $admin->getEMailAddress();
		if (!$adminEmail) {
			return false;
		}

		$message = $this->mailer->createMessage();
		$message->setTo([$adminEmail => $admin->getDisplayName()]);
		$message->useTemplate($template);
		$message->setFrom([$senderAddress => $senderName]);

		try {
			$failedRecipients = $this->mailer->send($message);
			if (count($failedRecipients) > 0) {
				return false;
			}
		} catch (\Exception $e) {
			return false;
		}

		return true;
	}

	protected function getExportTemplate(IUser $admin): IEMailTemplate {
		$l = $this->l10nFactory->get('data_request', $this->config->getUserValue($admin->getUID(), 'core', 'lang', $this->getDefaultLang()));
		$template = $this->mailer->createEMailTemplate('data_request.Export', []);

		$template->setSubject($l->t('Personal data export request'));

		$template->addHeader();
		$template->addHeading($l->t('Hello %s,', [$admin->getDisplayName()]));
		$template->addBodyText($l->t('The user %s, identified by user id "%s", has requested an export of their personal data. Please take action accordingly.', [$this->requester->getDisplayName(), $this->requester->getUID()]));

		$template->addFooter();

		return $template;
	}

	protected function getDeletionTemplate(IUser $admin): IEMailTemplate {
		$l = $this->l10nFactory->get('data_request', $this->config->getUserValue($admin->getUID(), 'core', 'lang', $this->getDefaultLang()));
		$template = $this->mailer->createEMailTemplate('data_request.Deletion', []);

		$template->setSubject($l->t('Account deletion request'));

		$template->addHeader();
		$template->addHeading($l->t('Hello %s,', [$admin->getDisplayName()]));
		$template->addBodyText($l->t('The user %s, identified by user id "%s", has requested to delete their account. Please take action accordingly.', [$this->requester->getDisplayName(), $this->requester->getUID()]));

		$template->addFooter();

		return $template;
	}

	protected function getAdmins(): array {
		$admins = $this->groupManager->get('admin')->searchUsers('');
		$admins = array_filter($admins, function (IUser $admin) {
			return $admin->getEMailAddress() !== null;
		});
		if (empty($admins)) {
			throw new HintedRuntime(
				'No admin has entered an email address',
				$this->l->t('No administrator has set an email address')
			);
		}
		return $admins;
	}
}
