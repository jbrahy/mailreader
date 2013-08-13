-- Here'content my DB structure

CREATE TABLE IF NOT EXISTS emails (
      email_id BIGINT(20) NOT NULL AUTO_INCREMENT,
      from_address VARCHAR(255) COLLATE utf8_unicode_ci NOT NULL,
      subject VARCHAR(255) COLLATE utf8_unicode_ci NOT NULL,
      body TEXT COLLATE utf8_unicode_ci NOT NULL,
      mail_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (email_id)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci AUTO_INCREMENT=1 ;

CREATE TABLE IF NOT EXISTS files (
    file_id BIGINT(20) NOT NULL AUTO_INCREMENT,
    email_id BIGINT(20) NOT NULL,
    file_name VARCHAR(255) COLLATE utf8_unicode_ci NOT NULL,
    mail_size VARCHAR(20) COLLATE utf8_unicode_ci NOT NULL,
    mime_type VARCHAR(100) COLLATE utf8_unicode_ci NOT NULL,
    PRIMARY KEY (file_id)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci AUTO_INCREMENT=1 ;
