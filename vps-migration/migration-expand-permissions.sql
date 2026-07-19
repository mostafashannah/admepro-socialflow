-- Seeds default rows for the new module-level permission keys (Clients &
-- Projects, Tools/Assets, CRM/Leads, Finance) added to Roles & Permissions,
-- matching exactly the app's PREVIOUS hardcoded role checks — so flipping
-- these hardcoded checks over to reading from role_permissions doesn't
-- change anyone's actual access. INSERT IGNORE is safe here since these
-- specific (role, permission_key) pairs have never existed before this
-- migration — it won't touch any existing hr.* rows you've already toggled.
INSERT IGNORE INTO role_permissions (role, permission_key, allowed) VALUES
  ('account_manager', 'clients.manage', 1),
  ('account_manager', 'assets.manage', 1),
  ('account_manager', 'crm.leads', 1),
  ('account_manager', 'finance.quotes', 1),
  ('account_manager', 'finance.full', 0),
  ('content_creator', 'clients.manage', 0),
  ('content_creator', 'assets.manage', 1),
  ('content_creator', 'crm.leads', 0),
  ('content_creator', 'finance.quotes', 0),
  ('content_creator', 'finance.full', 0),
  ('graphic_designer', 'clients.manage', 0),
  ('graphic_designer', 'assets.manage', 1),
  ('graphic_designer', 'crm.leads', 0),
  ('graphic_designer', 'finance.quotes', 0),
  ('graphic_designer', 'finance.full', 0),
  ('accountant', 'clients.manage', 0),
  ('accountant', 'assets.manage', 0),
  ('accountant', 'crm.leads', 0),
  ('accountant', 'finance.quotes', 1),
  ('accountant', 'finance.full', 1),
  ('hr', 'clients.manage', 0),
  ('hr', 'assets.manage', 0),
  ('hr', 'crm.leads', 0),
  ('hr', 'finance.quotes', 0),
  ('hr', 'finance.full', 0),
  ('office_boy', 'clients.manage', 0),
  ('office_boy', 'assets.manage', 0),
  ('office_boy', 'crm.leads', 0),
  ('office_boy', 'finance.quotes', 0),
  ('office_boy', 'finance.full', 0);
