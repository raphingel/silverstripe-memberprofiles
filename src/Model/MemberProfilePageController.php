<?php
// TODO this should be in the Controller namespace but that causes issues
// in SiteTree::getControllerName, so needs to be the same as MemberProfilePage
// Model for now

namespace Symbiote\MemberProfiles\Model;
use SilverStripe\Control\Session;
use SilverStripe\Security\Member;
use Symbiote\MemberProfiles\Model\MemberProfilePage;
use SilverStripe\Security\Security;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\FieldList;
use Symbiote\MemberProfiles\Forms\MemberProfileValidator;
use SilverStripe\Forms\Form;
use SilverStripe\Control\Director;
use SilverStripe\ORM\ValidationException;
use SilverStripe\Control\Controller;
use SilverStripe\ORM\DataObject;
use SilverStripe\Control\Email\Email;
use SilverStripe\SiteConfig\SiteConfig;
use Symbiote\MemberProfiles\Email\MemberConfirmationEmail;
use SilverStripe\Forms\LiteralField;
use Symbiote\MemberProfiles\Forms\CheckableVisibilityField;
use Symbiote\MemberProfiles\Controllers\MemberProfileViewer;
use PageController;

/**
 *
 */
class MemberProfilePageController extends PageController {

	private static $allowed_actions = array (
		'index',
		'RegisterForm',
		'afterregistration',
		'ProfileForm',
		'add',
		'AddForm',
		'confirm',
		'show'
	);

	/**
	 * @uses   MemberProfilePageController::indexRegister
	 * @uses   MemberProfilePageController::indexProfile
	 * @return array
	 */
	public function index() {
		if (isset($_GET['BackURL'])) {
			Session::set('MemberProfile.REDIRECT', $_GET['BackURL']);
		}
		$mode = Member::currentUser() ? 'profile' : 'register';
		$data = Member::currentUser() ? $this->indexProfile() : $this->indexRegister();
		if (is_array($data)) {
			return $this->customise($data)->renderWith(array('MemberProfilePage_'.$mode, MemberProfilePage::class, 'Page'));
		}
		return $data;
	}

	/**
	 * Allow users to register if registration is enabled.
	 *
	 * @return array
	 */
	protected function indexRegister() {
		if(!$this->AllowRegistration) return Security::permissionFailure($this, _t (
			'MemberProfiles.CANNOTREGPLEASELOGIN',
			'You cannot register on this profile page. Please login to edit your profile.'
		));

		return array (
			'Title'   => $this->obj('RegistrationTitle'),
			'Content' => $this->obj('RegistrationContent'),
			'Form'    => $this->RegisterForm()
		);
	}

	/**
	 * Allows users to edit their profile if they are in at least one of the
	 * groups this page is restricted to, and editing isn't disabled.
	 *
	 * If editing is disabled, but the current user can add users, then they
	 * are redirected to the add user page.
	 *
	 * @return array
	 */
	protected function indexProfile() {
		if(!$this->AllowProfileEditing) {
			if($this->AllowAdding && Injector::inst()->get(Member::class)->canCreate()) {
				return $this->redirect($this->Link('add'));
			}

			return Security::permissionFailure($this, _t(
				'MemberProfiles.CANNOTEDIT',
				'You cannot edit your profile via this page.'
			));
		}

		$member = Member::currentUser();

		foreach($this->Groups() as $group) {
			if(!$member->inGroup($group)) {
				return Security::permissionFailure($this);
			}
		}

		$form = $this->ProfileForm();
		$form->loadDataFrom($member);

		if($password = $form->Fields()->fieldByName('Password')) {
			if ($password->hasMethod('setCanBeEmpty')) {
				$password->setCanBeEmpty(false);
				$password->setValue(null);
				$password->setCanBeEmpty(true);
			} else {
				// If Password field is ReadonlyField or similar
				$password->setValue(null);
			}
		}

		return array (
			'Title' => $this->obj('ProfileTitle'),
			'Content' => $this->obj('ProfileContent'),
			'Form'  => $form
		);
	}

	/**
	 * @return MemberProfileViewer
	 */
	public function show() {
		if(!$this->AllowProfileViewing) {
			$this->httpError(404);
		}

		return MemberProfileViewer::create($this, 'show');
	}

	/**
	 * @uses   MemberProfilePageController::getProfileFields
	 * @return Form
	 */
	public function RegisterForm() {
		$form = new Form (
			$this,
			'RegisterForm',
			$this->getProfileFields('Registration'),
			new FieldList(
				new FormAction('register', _t('MemberProfiles.REGISTER', 'Register'))
			),
			new MemberProfileValidator($this->Fields())
		);


		if($form->hasExtension('FormSpamProtectionExtension')) {
			$form->enableSpamProtection( );
		}
		$this->extend('updateRegisterForm', $form);
		return $form;
	}

	/**
	 * Handles validation and saving new Member objects, as well as sending out validation emails.
	 */
	public function register($data, Form $form) {
		if($member = $this->addMember($form)) {
			if(!$this->RequireApproval && $this->EmailType != 'Validation' && !$this->AllowAdding) {
				$member->logIn();
			}

			if ($this->RegistrationRedirect) {
				if ($this->PostRegistrationTargetID) {
					$this->redirect($this->PostRegistrationTarget()->Link());
					return;
				}

				if ($sessionTarget = Session::get('MemberProfile.REDIRECT')) {
					Session::clear('MemberProfile.REDIRECT');
					if (Director::is_site_url($sessionTarget)) {
						$this->redirect($sessionTarget);
						return;
					}
				}
			}

			return $this->redirect($this->Link('afterregistration'));
		} else {
			return $this->redirectBack();
		}
	}

	/**
	 * Returns the after registration content to the user.
	 *
	 * @return array
	 */
	public function afterregistration() {
		return array (
			'Title'   => $this->obj('AfterRegistrationTitle'),
			'Content' => $this->obj('AfterRegistrationContent')
		);
	}

	/**
	 * @uses   MemberProfilePageController::getProfileFields
	 * @return Form
	 */
	public function ProfileForm() {
		$form = new Form (
			$this,
			'ProfileForm',
			$this->getProfileFields('Profile'),
			new FieldList(
				new FormAction('save', _t('MemberProfiles.SAVE', 'Save'))
			),
			new MemberProfileValidator($this->Fields(), Member::currentUser())
		);
		$this->extend('updateProfileForm', $form);
		return $form;
	}

	/**
	 * Updates an existing Member's profile.
	 */
	public function save(array $data, Form $form) {
		$member = Member::currentUser();

		$groupIds = $this->getSettableGroupIdsFrom($form, $member);
		$member->Groups()->setByIDList($groupIds);

		$form->saveInto($member);

		try {
			$member->write();
		} catch(ValidationException $e) {
			$form->sessionMessage($e->getResult()->message(), 'bad');
			return $this->redirectBack();
		}

		$form->sessionMessage (
			_t('MemberProfiles.PROFILEUPDATED', 'Your profile has been updated.'),
			'good'
		);
		return $this->redirectBack();
	}

	/**
	 * Allows members with the appropriate permissions to add/regsiter other
	 * members.
	 */
	public function add($request) {
		if(!$this->AllowAdding || !Injector::inst()->get(Member::class)->canCreate()) {
			return Security::permissionFailure($this, _t (
				'MemberProfiles.CANNOTADDMEMBERS',
				'You cannot add members via this page.'
			));
		}

		$data = array(
			'Title'   => _t('MemberProfiles.ADDMEMBER', 'Add Member'),
			'Content' => '',
			'Form'    => $this->AddForm()
		);

		return $this->customise($data)->renderWith(array('MemberProfilePage_add', MemberProfilePage::class, 'Page'));
	}

	/**
	 * @return Form
	 */
	public function AddForm() {
		$form = new Form (
			$this,
			'AddForm',
			$this->getProfileFields('Add'),
			new FieldList(
				new FormAction('doAdd', _t('MemberProfiles.ADD', 'Add'))
			),
			new MemberProfileValidator($this->Fields())
		);

		$this->extend('updateAddForm', $form);
		return $form;
	}

	/**
	 * Saves an add member form submission into a new member object.
	 */
	public function doAdd($data, $form) {
		if($this->addMember($form)) $form->sessionMessage(
			_t('MemberProfiles.MEMBERADDED', 'The new member has been added.'),
			'good'
		);

		return $this->redirectBack();
	}

	public function LoginLink() {
		return Controller::join_links(
			Injector::inst()->get(Security::class)->Link(),
			'login',
			'?BackURL=' . urlencode($this->Link())
		);
	}


	/**
	 * Gets the list of groups that can be set after the submission of a particular form
	 *
	 * This works around the problem with the checkboxsetfield which doesn't validate that the
	 * groups that the user has selected are not validated against the list of groups the user is
	 * allowed to choose from.
	 *
	 * @param Form   $form
	 * @param Member $member
	 */
	protected function getSettableGroupIdsFrom(Form $form, Member $member = null) {
		// first off check to see if groups were selected by the user. If so, we want
		// to remove that control from the form list (just in case someone's sent through an
		// ID for a group like, say, the admin's group...). It means we have to handle the setting
		// ourselves, but that's okay
		$groupField = $form->Fields()->dataFieldByName('Groups');
		// The list of selectable groups
		$groupIds = $allowedIds = $this->SelectableGroups()->map('ID', 'ID')->toArray();

		// we need to track the selected groups against the existing user's groups - this is
		// so that we don't accidentally remove them from the list of groups
		// a user might have been placed in via other means
		$existingIds = array();
		if ($member) {
			$existing = $member->Groups();
			if ($existing && $existing->Count() > 0) {
				$existingIds = $existing->map('ID', 'ID')->toArray();
				// remove any that are in the selectable groups map - we only want to
				// worry about those that aren't managed by this form
				foreach ($groupIds as $gid) {
					unset($existingIds[$gid]);
				}
			}
		}

		if ($groupField) {
			$givenIds = $groupField->Value();
			$groupIds = array();
			if ($givenIds) {
				foreach ($givenIds as $givenId) {
					if (isset($allowedIds[$givenId])) {
						$groupIds[] = $givenId;
					}
				}
			}
			$form->Fields()->removeByName('Groups');
		}

		foreach ($this->Groups()->column('ID') as $mustId) {
			$groupIds[] = $mustId;
		}

		foreach ($existingIds as $existingId) {
			if (!in_array($existingId, $groupIds)) {
				$groupIds[] = $existingId;
			}
		}

		return $groupIds;
	}

	/**
	 * Allows the user to confirm their account by clicking on the validation link in
	 * the confirmation email.
	 *
	 * @param HTTPRequest $request
	 * @return array
	 */
	public function confirm($request) {
		if(Member::currentUser()) {
			return Security::permissionFailure ($this, _t (
				'MemberProfiles.CANNOTCONFIRMLOGGEDIN',
				'You cannot confirm account while you are logged in.'
			));
		}

		if (
			$this->EmailType != 'Validation'
			|| (!$id = $request->param('ID')) || (!$key = $request->getVar('key')) || !is_numeric($id)
			|| !$member = DataObject::get_by_id(Member::class, $id)
		) {
			$this->httpError(404);
		}

		if($member->ValidationKey != $key || !$member->NeedsValidation) {
			$this->httpError(403, 'You cannot validate this member.');
		}

		$member->NeedsValidation = false;
		$member->ValidationKey   = null;
		$member->write();

		$this->extend('onConfirm', $member);

		$member->logIn();

		return array (
			'Title'   => $this->obj('ConfirmationTitle'),
			'Content' => $this->obj('ConfirmationContent')
		);
	}

	/**
	 * Attempts to save either a registration or add member form submission
	 * into a new member object, returning NULL on validation failure.
	 *
	 * @return Member|null
	 */
	protected function addMember($form) {
		$member   = new Member();
		$groupIds = $this->getSettableGroupIdsFrom($form);

		$form->saveInto($member);

		$member->ProfilePageID   = $this->ID;
		$member->NeedsValidation = ($this->EmailType == 'Validation');
		$member->NeedsApproval   = $this->RequireApproval;

		try {
			$member->write();
		} catch(ValidationException $e) {
			$form->sessionMessage($e->getResult()->message(), 'bad');
			return;
		}

		// set after member is created otherwise the member object does not exist
		$member->Groups()->setByIDList($groupIds);

		// If we require admin approval, send an email to the admin and delay
		// sending an email to the member.
		if ($this->RequireApproval) {
			$groups = $this->ApprovalGroups();
			$emails = array();

			if ($groups) foreach ($groups as $group) {
				foreach ($group->Members() as $_member) {
					if ($_member->Email) $emails[] = $_member->Email;
				}
			}

			if ($emails) {
				$email   = new Email();
				$config  = SiteConfig::current_site_config();
				$approve = Controller::join_links(
					Director::baseURL(), 'member-approval', $member->ID, '?token=' . $member->ValidationKey
				);

				$email->setSubject("Registration Approval Requested for $config->Title");
				$email->setBcc(implode(',', array_unique($emails)));
				$email->setTemplate('MemberRequiresApprovalEmail');
				$email->populateTemplate(array(
					'SiteConfig'  => $config,
					'Member'      => $member,
					'ApproveLink' => Director::absoluteURL($approve)
				));

				$email->send();
			}
		} elseif($this->EmailType != 'None') {
			$email = MemberConfirmationEmail::create($this, $member);
			$email->send();
		}

		$this->extend('onAddMember', $member);
		return $member;
	}

	/**
	 * @param string $context
	 * @return FieldSet
	 */
	protected function getProfileFields($context) {
		$profileFields = $this->Fields();
		$fields        = new FieldList();

		// depending on the context, load fields from the current member
		if(Member::currentUser() && $context != 'Add') {
			$memberFields = Member::currentUser()->getMemberFormFields();
		} else {
			$memberFields = singleton(Member::class)->getMemberFormFields();
		}

		// use the default registration fields for adding members
		if($context == 'Add') {
			$context = 'Registration';
		}

		if ($this->AllowProfileViewing
			&& $profileFields->find('PublicVisibility', 'MemberChoice')
		) {
			$fields->push(new LiteralField(
				'VisibilityNote',
				'<p>' . _t(
					'MemberProfiles.CHECKVISNOTE',
					'Check fields below to make them visible on your public profile.'
				) . '</p>'
			));
		}

		foreach($profileFields as $profileField) {
			$visibility  = $profileField->{$context . 'Visibility'};
			$name        = $profileField->MemberField;
			$memberField = $memberFields->dataFieldByName($name);

			// handle the special case of the Groups control so that only allowed groups can be selected
			if ($name == 'Groups') {
				$availableGroups = $this->data()->SelectableGroups()->map('ID', 'Title');
				$memberField->setSource($availableGroups);
			}

			if(!$memberField || $visibility == 'Hidden') continue;

			$field = clone $memberField;

			if($visibility == 'Readonly') {
				$field = $field->performReadonlyTransformation();
			}

			$field->setTitle($profileField->Title);
			$field->setDescription($profileField->Note);

			if($context == 'Registration' && $profileField->DefaultValue) {
				$field->setValue($profileField->DefaultValue);
			}

			if($profileField->CustomError) {
				$field->setCustomValidationMessage($profileField->CustomError);
			}

			$canSetVisibility = (
				   $this->AllowProfileViewing
				&& $profileField->PublicVisibility != 'Hidden'
			);
			if ($canSetVisibility) {
				$field = new CheckableVisibilityField($field);

				if ($profileField->PublicVisibility == 'Display') {
					$field->makeAlwaysVisible();
				} else {
					$field->getCheckbox()->setValue($profileField->PublicVisibilityDefault);
				}
			}

			$fields->push($field);
		}

		$this->extend('updateProfileFields', $fields);
		return $fields;
	}
}
