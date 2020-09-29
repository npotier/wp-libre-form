<?php

namespace WPLF;

class Submission {
  public $ID;
  public $uuid;
  public $referrer;

  public $fields = []; // todo: rename to entries
  public $meta = [];

  private $form;
  private $rawData;

  public function __construct(Form $form, ?array $data = null) {
    $this->form = $form;
    $this->ID = ((int) $data['id']) ?: null;
    $this->uuid = $data['uuid'] ?? null;
    $this->referrer = json_decode($data['referrerData'], true);

    // Unset the values after using to prevent them from ending under meta
    unset($data['id']);
    unset($data['uuid']);
    unset($data['referrerData']);

    if ($data) {
      $this->rawData = $data;

      foreach ($this->rawData as $name => $v) {
        if (strpos($name, 'field') === 0) {
          $this->fields[$this->form->getFieldOriginalName($name)] = $v;
        } else {
          // Other columns in the table are metadata
          $this->meta[$name] = $v;
        }
      }
    }
  }

  public function getForm() {
    return $this->form;
  }

  public function getId() {
    return $this->ID;
  }

  public function getUuid() {
    return $this->uuid;
  }

  public function getReferrer() {
    return $this->referrer;
  }

  public function getField(string $fieldName) {
    return $this->fields[$fieldName] ?? null;
  }

  public function getFields() {
    return $this->fields;
  }

  public function getMeta() {
    return $this->meta;
  }

  /**
   * Broken. Doesn't remove uploads for some reason.
   *
   */
  public function delete($removeUploads = true) {
    $fields = $this->getFields();
    $historyId = (int) $this->getMeta()['historyId'];
    $formFields = $this->form->getFields($historyId);

    foreach ($fields as $name => $value) {
      $k = array_search($name, array_column($fields, 'name'));
      $formField = $formFields[$k] ?? false;

      $type = $formField['type'];

      if ($removeUploads && $type === 'file') {
        $path = $value['path'];

        if (!$this->io->deleteFile($path)) {
          isDebug() && log("Unable to delete file $path");
        }
      } elseif ($removeUploads && $type === 'attachment') {
        $id = $value['id'];

        if (!wp_delete_attachment($id, true)) {
          isDebug() && log("Unable to delete attachment $id");
        }
      }
    }

    return libreform()->io->destroySubmission($this);
  }

  /**
   * $fields is an associative array, using keys for field names and values for the field values.
   *
   * @todo move to IO
   */
  public function create($fields) {
    $form = $this->form;

    $fields = apply_filters('wplfFieldsBeforeValidateSubmission', $fields);
    [$valid, $error] = $this->validate($fields);

    if ($error instanceof Error) {
      throw new Error($error->getMessage(), $error->getData());
    }

    $fields = apply_filters('wplfFieldsAfterValidateSubmission', $fields);
    try {
      $id = libreform()->io->insertSubmission($form, $fields);
      $newSub = libreform()->io->getFormSubmissionById($form, $id); // contains fields

      // return $newSub;
      $this->ID = $id;
      $this->uuid = $newSub->getUuid();
      $this->fields = $newSub->getFields();
      $this->meta = $newSub->getMeta();
      $this->referrer = $newSub->getReferrer();
    } catch (Error $e) {
      throw $e; //
    }

    do_action('wplfAfterSubmission', $this);

    if (apply_filters('wplfUseDefaultAfterSubmission', true, $this)) {
      $this->afterSubmission();
    }

    return $this->ID;
  }

  public function afterSubmission() {
    $email = $this->form->getEmailNotificationData();
    $data = apply_filters('wplfEmailNotificationData', $email, $this);
    $wplf = \libreform(); // There's no access to the core plugin object from this class, but we need to do selector conversion.

    if ($data['enabled']) {
      $to = $wplf->selectors->parse($data['to'], $this->form, $this);
      $from = $wplf->selectors->parse($data['from'], $this->form, $this);
      $subject = $wplf->selectors->parse($data['subject'], $this->form, $this);
      $content = $wplf->selectors->parse($data['content'], $this->form, $this);

      $headers = apply_filters('wplfEmailNotificationHeaders', [
        'From' => $from,
      ], $this);
      $attachments = apply_filters('wplfEmailNotificationAttachment', [], $this);


      if (!$this->sendEmail($to, $subject, $content, $headers, $attachments)) {
        isDebug() && log("Failed to send email");
      }
    }
  }

  public function sendEmail(string $to, string $subject, string $content, array $headers = [], array $attachments = []) {
    // wp_mail hates array keys in headers
    $actualHeaders = [];

    foreach ($headers as $k => $v) {
      $actualHeaders[] = "$k: $v";
    }


    return wp_mail($to, $subject, $content, $actualHeaders, $attachments);
  }

  public function validate($data) {
    $form = $this->form;
    $valid = false;
    $error = null;

    $honeypotEnabled = apply_filters('wplfEnableHoneypot', true, $form);
    $requiredEnabled = apply_filters('wplfEnableRequiredValidation', true, $form);
    $additionalFieldsEnabled = apply_filters('wplfEnableAdditionalFieldsValidation', true, $form);

    try {
      $honeypotEnabled && $this->validateHoneypot($data);
      $requiredEnabled && $this->validateFieldsWithRequired($data);
      $additionalFieldsEnabled && $this->validateAdditionalFields($data);

      do_action('wplfValidateSubmission', $data, $this);

      $valid = true;
    } catch (Error $e) {
      $valid = false;
      $error = $e;
    }

    return [$valid, $error];
  }

  /**
   * Validate that fields with the required attribute
   * are not empty.
   *
   * @todo Check against (possible) pattern attribute
   * @todo Throw Error with (possible) title attribute
   *
   * Todo implementation requires storing the pattern and title attrs in wplfFields.
   */
  public function validateFieldsWithRequired($data): void {
    $form = $this->form;
    $fields = $form->getFields();

    $missing = [];
    foreach ($fields as $field) {
      $required = $field['required'];
      $name = $field['name'];
      $value = $data[$name] ?? false;

      $valueIsEmpty = empty($value);
      // $valueIsArray = !$valueIsEmpty ? is_array($value) : false;
      // $valueIsFileArray = $valueIsArray && isFileArray($value);

      if ($required && $valueIsEmpty) {
        $missing[] = $name;
      }
    }

    if (!empty($missing)) {
      throw new Error(__('Required fields are missing.', 'wplf'), [
        'requiredFields' => $missing,
      ]);
    }
  }

  /**
   * Check for presence of fields that weren't in the form
   * and that be malicious. Additional fields will also throw SQL errors,
   * so we want none.
   */
  public function validateAdditionalFields($data): void {
    $form = $this->form;
    $fields = $form->getFields();

    $formFieldNames = array_map(function ($field) {
      return $field['name'];
    }, $fields);
    $notAllowed = [];
    $whitelist = apply_filters('wplfAllowedFormFields', array_merge($formFieldNames, $form->getAdditionalFields()), $form);

    foreach ($data as $key => $value) {
      $fieldIsWhiteListed = in_array($key, $whitelist);

      if ($fieldIsWhiteListed) {
        continue;
      }

      $notAllowed[] = $key;
    }

    if (!empty($notAllowed)) {
      throw new Error(__('Additional fields are present.', 'wplf'), [
        'additionalFields' => $notAllowed,
      ]);
    }
  }

  /**
   * Ensure that a dumb bot isn't spamming submissions. Error is intentionally vague.
   */
  public function validateHoneypot($data): void {
    if (!empty($data['_fcaptcha'])) {
      do_action('wplfHoneypotTriggered', $data, $this);

      throw new Error('Captcha was wrong', []);
    }
  }
}
