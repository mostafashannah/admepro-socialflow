-- Run once as the MySQL admin:
--   mysql -u root -p socialflow < /var/www/socialflow/migration-zernio.sql
-- LinkedIn comments store "<post urn>||<comment id>" in external_id, which can be longer than 64 characters.
ALTER TABLE customer_messages MODIFY external_id VARCHAR(255) DEFAULT NULL;
