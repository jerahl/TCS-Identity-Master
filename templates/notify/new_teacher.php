<?php
/** @var array $person @var string $fullName @var string $displayName @var string $school @var string $position @var string $startDate */
use App\View\View;

echo View::partial('notify/_account', [
    'person' => $person, 'fullName' => $fullName, 'school' => $school,
    'position' => $position, 'startDate' => $startDate,
    'heading' => 'New Teacher Technology Orientation Checklist',
]);
?>

<p style="font-size:14px;">Welcome to Tuscaloosa City Schools, <?= e($displayName) ?>. Complete the steps
below to activate and secure your accounts. Check each item off as you go.</p>

<h2 class="section">First sign-in</h2>
<ol class="steps">
  <li>Sign in to your district account at <a href="https://portal.office.com">portal.office.com</a>
      using the username and email above and the temporary password provided by your school.</li>
  <li>Set a permanent password and register for self-service password reset at
      <a href="https://aka.ms/sspr">aka.ms/sspr</a>.</li>
  <li>Turn on multi-factor authentication when prompted.</li>
</ol>

<h2 class="section">Instructional systems</h2>
<ul class="steps">
  <li>Log in to <strong>PowerSchool</strong> (gradebook &amp; attendance) at
      <a href="https://powerschool.tuscaloosacityschools.com">your PowerSchool portal</a>.</li>
  <li>Log in to <strong>Google Workspace</strong> (Classroom, Drive) at
      <a href="https://classroom.google.com">classroom.google.com</a> with your district email.</li>
  <li>Access your <strong>district email &amp; calendar</strong> in Outlook.</li>
  <li>Complete the required <strong>technology acceptable-use</strong> and
      <strong>data-privacy</strong> trainings.</li>
</ul>

<h2 class="section">Getting help</h2>
<ul class="steps">
  <li>Submit a help-desk ticket at <a href="https://help.tuscaloosacityschools.com">help.tuscaloosacityschools.com</a>
      or contact your school's technology contact.</li>
</ul>

<p class="note">The links and steps above are a
<span class="placeholder">starting template</span> — replace them with the district's
current New Teacher orientation content. Account details are pulled live from the
Identity Master.</p>
