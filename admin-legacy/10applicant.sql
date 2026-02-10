START TRANSACTION;

SET @base = '{
  "personal":{"title":"Mr","first_name":"emp1","last_name":"lastname","preferred_name":"emp","dob":"1988-05-02","phone":"0777777777","email":"test@gmail.com","phone_home":"908888","address_line1":"Flat 12 Wayside Court","address_line2":"60 The Grove, Isleworth","address_town":"isleworth","address_county":"middlesex","address_postcode":"TW7 4JR"},
  "role":{"position_applied_for":"Care Assistant (Days)","work_type":"Full-time","preferred_shift_pattern":"Days","hours_per_week":"40","earliest_start_date":"2026-02-04","notice_period":"1","heard_about_role":"Indeed","extra_notes":""},
  "checks":{"has_right_to_work":"yes","requires_sponsorship":"no","visa_type":"skilled","rtw_notes":"","has_current_dbs":"yes","dbs_type":"yes","dbs_notes":"asd","on_update_service":"1","barred_from_working":"1"},
  "work_history":{"jobs":[{"employer_name":"ttk","employer_location":"llford","job_title":"care assistant","organisation_type":"Care home","start_month":"2","start_year":"","end_month":"2","end_year":"","is_current":"1","main_duties":"","reason_for_leaving":"looking for better opprtunity","is_care_role":"","can_contact_now":"yes"},{"employer_name":"none","employer_location":"as","job_title":"asdf","organisation_type":"","start_month":"","start_year":"","end_month":"","end_year":"","main_duties":"","reason_for_leaving":"","is_care_role":"","can_contact_now":""}],"gap_explanations":""},
  "education":{"highest_education_level":"GCSE or equivalent","qualifications":[{"name":"qlicifation","provider":"","date_achieved":"","notes":""}],"registrations":[{"body":"","number":"3323","renewal_date":"","notes":""}],"training_summary":"","training":{"moving_handling":"1","medication":"1","other":"1"}},
  "references":{"references":[{"name":"ads","job_title":"ads","relationship":"referece","organisation":"reare","email":"","phone":"","reference_type":"Employer","can_contact_now":"yes","address":""},{"name":"ads","job_title":"","relationship":"asdffa","organisation":"","email":"sdfads@gmail.com","phone":"070959","reference_type":"Academic","can_contact_now":"yes","address":"sasd"}],"reference_notes":"bnotea "},
  "declaration":{"typed_signature":"acd","signature_date":"2026-02-06","confirm_true_information":"1","aware_of_false_info_consequences":"1","consent_to_processing":"1"}
}';

INSERT INTO hr_applications
  (public_token, status, applicant_name, email, phone, payload_json, created_at, updated_at)
VALUES
(
  REPLACE(UUID(), '-', ''),
  'submitted',
  'Emp1 Lastname',
  'test+emp1@gmail.com',
  '0777777701',
  JSON_SET(CAST(@base AS JSON),
    '$.personal.first_name','Emp1',
    '$.personal.last_name','Lastname',
    '$.personal.preferred_name','Emp1',
    '$.personal.email','test+emp1@gmail.com',
    '$.personal.phone','0777777701'
  ),
  UTC_TIMESTAMP() - INTERVAL 10 MINUTE,
  UTC_TIMESTAMP() - INTERVAL 10 MINUTE
),
(
  REPLACE(UUID(), '-', ''),
  'submitted',
  'Emp2 Lastname',
  'test+emp2@gmail.com',
  '0777777702',
  JSON_SET(CAST(@base AS JSON),
    '$.personal.first_name','Emp2',
    '$.personal.last_name','Lastname',
    '$.personal.preferred_name','Emp2',
    '$.personal.email','test+emp2@gmail.com',
    '$.personal.phone','0777777702'
  ),
  UTC_TIMESTAMP() - INTERVAL 9 MINUTE,
  UTC_TIMESTAMP() - INTERVAL 9 MINUTE
),
(
  REPLACE(UUID(), '-', ''),
  'submitted',
  'Emp3 Lastname',
  'test+emp3@gmail.com',
  '0777777703',
  JSON_SET(CAST(@base AS JSON),
    '$.personal.first_name','Emp3',
    '$.personal.last_name','Lastname',
    '$.personal.preferred_name','Emp3',
    '$.personal.email','test+emp3@gmail.com',
    '$.personal.phone','0777777703'
  ),
  UTC_TIMESTAMP() - INTERVAL 8 MINUTE,
  UTC_TIMESTAMP() - INTERVAL 8 MINUTE
),
(
  REPLACE(UUID(), '-', ''),
  'submitted',
  'Emp4 Lastname',
  'test+emp4@gmail.com',
  '0777777704',
  JSON_SET(CAST(@base AS JSON),
    '$.personal.first_name','Emp4',
    '$.personal.last_name','Lastname',
    '$.personal.preferred_name','Emp4',
    '$.personal.email','test+emp4@gmail.com',
    '$.personal.phone','0777777704'
  ),
  UTC_TIMESTAMP() - INTERVAL 7 MINUTE,
  UTC_TIMESTAMP() - INTERVAL 7 MINUTE
),
(
  REPLACE(UUID(), '-', ''),
  'submitted',
  'Emp5 Lastname',
  'test+emp5@gmail.com',
  '0777777705',
  JSON_SET(CAST(@base AS JSON),
    '$.personal.first_name','Emp5',
    '$.personal.last_name','Lastname',
    '$.personal.preferred_name','Emp5',
    '$.personal.email','test+emp5@gmail.com',
    '$.personal.phone','0777777705'
  ),
  UTC_TIMESTAMP() - INTERVAL 6 MINUTE,
  UTC_TIMESTAMP() - INTERVAL 6 MINUTE
),
(
  REPLACE(UUID(), '-', ''),
  'submitted',
  'Emp6 Lastname',
  'test+emp6@gmail.com',
  '0777777706',
  JSON_SET(CAST(@base AS JSON),
    '$.personal.first_name','Emp6',
    '$.personal.last_name','Lastname',
    '$.personal.preferred_name','Emp6',
    '$.personal.email','test+emp6@gmail.com',
    '$.personal.phone','0777777706'
  ),
  UTC_TIMESTAMP() - INTERVAL 5 MINUTE,
  UTC_TIMESTAMP() - INTERVAL 5 MINUTE
),
(
  REPLACE(UUID(), '-', ''),
  'submitted',
  'Emp7 Lastname',
  'test+emp7@gmail.com',
  '0777777707',
  JSON_SET(CAST(@base AS JSON),
    '$.personal.first_name','Emp7',
    '$.personal.last_name','Lastname',
    '$.personal.preferred_name','Emp7',
    '$.personal.email','test+emp7@gmail.com',
    '$.personal.phone','0777777707'
  ),
  UTC_TIMESTAMP() - INTERVAL 4 MINUTE,
  UTC_TIMESTAMP() - INTERVAL 4 MINUTE
),
(
  REPLACE(UUID(), '-', ''),
  'submitted',
  'Emp8 Lastname',
  'test+emp8@gmail.com',
  '0777777708',
  JSON_SET(CAST(@base AS JSON),
    '$.personal.first_name','Emp8',
    '$.personal.last_name','Lastname',
    '$.personal.preferred_name','Emp8',
    '$.personal.email','test+emp8@gmail.com',
    '$.personal.phone','0777777708'
  ),
  UTC_TIMESTAMP() - INTERVAL 3 MINUTE,
  UTC_TIMESTAMP() - INTERVAL 3 MINUTE
),
(
  REPLACE(UUID(), '-', ''),
  'submitted',
  'Emp9 Lastname',
  'test+emp9@gmail.com',
  '0777777709',
  JSON_SET(CAST(@base AS JSON),
    '$.personal.first_name','Emp9',
    '$.personal.last_name','Lastname',
    '$.personal.preferred_name','Emp9',
    '$.personal.email','test+emp9@gmail.com',
    '$.personal.phone','0777777709'
  ),
  UTC_TIMESTAMP() - INTERVAL 2 MINUTE,
  UTC_TIMESTAMP() - INTERVAL 2 MINUTE
),
(
  REPLACE(UUID(), '-', ''),
  'submitted',
  'Emp10 Lastname',
  'test+emp10@gmail.com',
  '0777777710',
  JSON_SET(CAST(@base AS JSON),
    '$.personal.first_name','Emp10',
    '$.personal.last_name','Lastname',
    '$.personal.preferred_name','Emp10',
    '$.personal.email','test+emp10@gmail.com',
    '$.personal.phone','0777777710'
  ),
  UTC_TIMESTAMP() - INTERVAL 1 MINUTE,
  UTC_TIMESTAMP() - INTERVAL 1 MINUTE
);

COMMIT;
