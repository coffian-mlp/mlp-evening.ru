ALTER TABLE users ADD COLUMN roles_json JSON NULL;

UPDATE users SET roles_json=CASE
    WHEN role='admin' THEN JSON_ARRAY('user','admin')
    WHEN role='moderator' THEN JSON_ARRAY('user','moderator')
    ELSE JSON_ARRAY('user')
END WHERE roles_json IS NULL;

ALTER TABLE users ADD CONSTRAINT user_roles_baseline CHECK (
    roles_json IS NULL OR (JSON_TYPE(roles_json)='ARRAY' AND JSON_CONTAINS(roles_json,JSON_QUOTE('user')))
);
