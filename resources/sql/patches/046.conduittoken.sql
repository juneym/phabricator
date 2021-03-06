CREATE TABLE phabricator_conduit.conduit_certificatetoken (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  userPHID VARCHAR(64) BINARY NOT NULL,
  token VARCHAR(64),
  dateCreated INT UNSIGNED NOT NULL,
  dateModified INT UNSIGNED NOT NULL
);

ALTER TABLE phabricator_conduit.conduit_certificatetoken
  ADD UNIQUE KEY (userPHID);
ALTER TABLE phabricator_conduit.conduit_certificatetoken
  ADD UNIQUE KEY (token);
