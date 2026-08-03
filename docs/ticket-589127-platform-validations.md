# Ticket 589127 — platform validations performed (Neumológica Moodle)

- **Component:** `local_coursedynamicrules` (Smart Rules AI)
- **Site:** educacion.neumologica.org (client production)
- **Purpose:** Record the checks run directly on the client's Moodle admin UI that back each verdict
  in [`ticket-589127-closure.md`](ticket-589127-closure.md). Evidence source: 13 screenshots the
  client/support shared (named by ticket message date).

## Validations and what each showed

| # | Where in Moodle | What was checked | Result | Backs ticket point |
|---|-----------------|------------------|--------|--------------------|
| V1 | Course EPOC – AZCHOOL → Smart Rules AI | The reminder rules | **Two near-identical rules** ("Finalización del curso" and "Finalización del curso - 3") on the same Customcert activity, **both `Inactiva`**; condition = users who have NOT completed the activity; action = "Enviar notificación … a los usuarios" | Duplicate-rule risk; rule state |
| V2 | Course EPOC – AZCHOOL → Participants | Role of the reported teacher | `caguirre@neumologica.org` enrolled with role **Estudiante**, active, no groups | #1 — teacher reminder is expected behaviour (he IS a student) |
| V3 | Site admin → Users → Browse list → filter by email | `fncdocencia@gmail.com` | **Exactly one** account: "Usuario Prueba" (username `usuarioprueba`) — first name is NOT "Deysy" | #3 — "Hola Deysy" is a name hard-coded in the message body, not code |
| V4 | Course → Smart Rules AI → action row | Whether an action can be edited/inspected | Actions offer **add/delete only** (no edit); list description shows the generic "…a los usuarios" (role/body detail only from 1.7.0) | Diagnosis constraint; fix = delete + recreate |
| V5 | Mailbox `noreply@neumologica.org` → Sent items | Certificate emails across courses | EPOC – AZCHOOL sends the **default** certificate text; Espirometría / Portal Nómina / Inducción send the **personalised** text; **all carry the certificate PDF attachment** | #2 — certificate emails come from the certificate module (the plugin cannot attach files); per-course template difference |
| V6 | Site admin → Users → Default preferences | `maildigest` default | Set to **"Completo (correo diario)"**; validated teachers receive a daily 5 pm digest | Forum notifications (already OK — core config) |
| V7 | Course → Participants → Enrolment methods; Site admin → Language customisation | Welcome message config; English subject string | Welcome message is per enrolment method; `emailresetconfirmationsubject` is the default core language string | #5 and #6 (already OK — core config) |
| V8 | Support documentation (notification targeting) | Primary vs copy recipients model | Primary = Student example; matches the shipped 1.6.2 targeting model | Recipient model context |

## Conclusions drawn from these validations

- **#1 teacher reminder** → not a plugin defect (V2: the user holds the Student role; the rule targets
  students who have not completed). Manage via enrolment/role if he must not be notified.
- **#2 certificates** → not this plugin (V5: certificate-module emails with PDF; per-course template).
  Unify the certificate template for EPOC – AZCHOOL.
- **#3 wrong name** → configuration (V3: single account, name not "Deysy" → literal name typed in the
  message body). Fix: delete + recreate the notification using the `{$a->firstname}` marker.
- **#4 in-course alerts** → out of scope (different component; client conceded on 2026-06-01).
- **Duplicate rules** (V1) → keep a single active rule to avoid duplicate emails.

## Version note

Throughout the ticket the client site ran an installed release **≤ 1.6.3**; the role/name fixes they
confirmed working (2026-06-18) shipped in 1.6.2/1.6.3. The consolidated **1.7.0** (S1–S7 + stored-XSS)
is a separate release track and was not required to close these configuration points.
