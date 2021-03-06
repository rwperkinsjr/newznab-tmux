DELETE FROM prehash
WHERE MD5(title) != MD5;
ALTER TABLE prehash ADD COLUMN sha1 VARCHAR(40) NOT NULL DEFAULT '';
ALTER TABLE prehash MODIFY COLUMN md5 VARCHAR(32) NOT NULL DEFAULT '';

UPDATE prehash SET sha1 = sha1(title);
CREATE UNIQUE INDEX ix_prehash_sha1 ON prehash(sha1);
