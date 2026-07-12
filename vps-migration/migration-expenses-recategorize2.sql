-- Run after migration-expenses-recategorize.sql. Moves specific General
-- Expenses rows into more precise categories per explicit request:
-- Electricity -> Rent & Utilities, Freepik -> Tools & Software, and common
-- pantry/office items -> the new Office Supplies category.


UPDATE expenses SET category='office_supplies' WHERE type='out' AND category='general' AND description='Water and sugar' AND amount=240.0 AND date='2026-01-05';
UPDATE expenses SET category='rent' WHERE type='out' AND category='general' AND description='Electricity' AND amount=867.0 AND date='2026-01-06';
UPDATE expenses SET category='office_supplies' WHERE type='out' AND category='general' AND description='Water' AND amount=360.0 AND date='2026-01-12';
UPDATE expenses SET category='office_supplies' WHERE type='out' AND category='general' AND description='Buffet' AND amount=394.0 AND date='2026-01-14';
UPDATE expenses SET category='office_supplies' WHERE type='out' AND category='general' AND description='Buffet' AND amount=509.0 AND date='2026-01-20';
UPDATE expenses SET category='office_supplies' WHERE type='out' AND category='general' AND description='Buffet' AND amount=1600.0 AND date='2026-01-21';
UPDATE expenses SET category='office_supplies' WHERE type='out' AND category='general' AND description='Coffee' AND amount=135.0 AND date='2026-01-27';
UPDATE expenses SET category='office_supplies' WHERE type='out' AND category='general' AND description='Coffee' AND amount=270.0 AND date='2026-02-03';
UPDATE expenses SET category='office_supplies' WHERE type='out' AND category='general' AND description='Buffet' AND amount=1800.0 AND date='2026-02-04';
UPDATE expenses SET category='office_supplies' WHERE type='out' AND category='general' AND description='Water' AND amount=360.0 AND date='2026-02-05';
UPDATE expenses SET category='rent' WHERE type='out' AND category='general' AND description='Electricity' AND amount=1072.0 AND date='2026-02-05';
UPDATE expenses SET category='office_supplies' WHERE type='out' AND category='general' AND description='Milk' AND amount=159.0 AND date='2026-02-15';
UPDATE expenses SET category='rent' WHERE type='out' AND category='general' AND description='Electricity' AND amount=2337.0 AND date='2026-03-05';
UPDATE expenses SET category='tools' WHERE type='out' AND category='general' AND description='Freepik' AND amount=2000.0 AND date='2026-03-16';
UPDATE expenses SET category='tools' WHERE type='out' AND category='general' AND description='Freepik' AND amount=1400.0 AND date='2026-03-18';
UPDATE expenses SET category='office_supplies' WHERE type='out' AND category='general' AND description='Tissues & coffee' AND amount=550.0 AND date='2026-03-24';
UPDATE expenses SET category='office_supplies' WHERE type='out' AND category='general' AND description='Water' AND amount=220.0 AND date='2026-03-24';
UPDATE expenses SET category='tools' WHERE type='out' AND category='general' AND description='Freepik' AND amount=3000.0 AND date='2026-03-30';
UPDATE expenses SET category='rent' WHERE type='out' AND category='general' AND description='Electricity' AND amount=1840.0 AND date='2026-04-08';
UPDATE expenses SET category='office_supplies' WHERE type='out' AND category='general' AND description='Buffet' AND amount=3040.0 AND date='2026-04-08';
UPDATE expenses SET category='tools' WHERE type='out' AND category='general' AND description='Freepik' AND amount=2400.0 AND date='2026-04-12';
UPDATE expenses SET category='tools' WHERE type='out' AND category='general' AND description='Freepik' AND amount=750.0 AND date='2026-04-20';
UPDATE expenses SET category='office_supplies' WHERE type='out' AND category='general' AND description='Buffet' AND amount=1000.0 AND date='2026-04-22';
UPDATE expenses SET category='tools' WHERE type='out' AND category='general' AND description='Freepik' AND amount=750.0 AND date='2026-04-22';
UPDATE expenses SET category='tools' WHERE type='out' AND category='general' AND description='Freepik' AND amount=2000.0 AND date='2026-04-23';
UPDATE expenses SET category='tools' WHERE type='out' AND category='general' AND description='Freepik' AND amount=700.0 AND date='2026-04-28';
UPDATE expenses SET category='office_supplies' WHERE type='out' AND category='general' AND description='Buffet' AND amount=350.0 AND date='2026-04-28';
UPDATE expenses SET category='office_supplies' WHERE type='out' AND category='general' AND description='Water and milk' AND amount=470.0 AND date='2026-04-29';
UPDATE expenses SET category='office_supplies' WHERE type='out' AND category='general' AND description='Coffee beans' AND amount=100.0 AND date='2026-04-30';
UPDATE expenses SET category='office_supplies' WHERE type='out' AND category='general' AND description='Milk' AND amount=175.0 AND date='2026-04-03';
UPDATE expenses SET category='tools' WHERE type='out' AND category='general' AND description='Freepik' AND amount=750.0 AND date='2026-05-04';
UPDATE expenses SET category='tools' WHERE type='out' AND category='general' AND description='Freepik' AND amount=750.0 AND date='2026-05-05';
UPDATE expenses SET category='office_supplies' WHERE type='out' AND category='general' AND description='Coffee beans' AND amount=100.0 AND date='2026-05-05';
UPDATE expenses SET category='office_supplies' WHERE type='out' AND category='general' AND description='Nescafe' AND amount=95.0 AND date='2026-05-05';
UPDATE expenses SET category='rent' WHERE type='out' AND category='general' AND description='Electricity' AND amount=586.0 AND date='2026-05-06';
UPDATE expenses SET category='office_supplies' WHERE type='out' AND category='general' AND description='Buffet' AND amount=1178.0 AND date='2026-05-10';
UPDATE expenses SET category='tools' WHERE type='out' AND category='general' AND description='Freepik' AND amount=2000.0 AND date='2026-05-12';
UPDATE expenses SET category='office_supplies' WHERE type='out' AND category='general' AND description='Buffet' AND amount=630.0 AND date='2026-05-13';
UPDATE expenses SET category='office_supplies' WHERE type='out' AND category='general' AND description='Buffet' AND amount=3495.0 AND date='2026-05-17';
UPDATE expenses SET category='tools' WHERE type='out' AND category='general' AND description='Freepik' AND amount=2000.0 AND date='2026-05-18';
UPDATE expenses SET category='tools' WHERE type='out' AND category='general' AND description='Freepik' AND amount=750.0 AND date='2026-05-24';
UPDATE expenses SET category='tools' WHERE type='out' AND category='general' AND description='Freepik' AND amount=1500.0 AND date='2026-05-25';
UPDATE expenses SET category='office_supplies' WHERE type='out' AND category='general' AND description='Water' AND amount=245.0 AND date='2026-06-03';
UPDATE expenses SET category='office_supplies' WHERE type='out' AND category='general' AND description='Coffee' AND amount=205.0 AND date='2026-06-03';
UPDATE expenses SET category='tools' WHERE type='out' AND category='general' AND description='Freepik' AND amount=750.0 AND date='2026-06-04';
UPDATE expenses SET category='office_supplies' WHERE type='out' AND category='general' AND description='Milk' AND amount=360.0 AND date='2026-06-08';
UPDATE expenses SET category='tools' WHERE type='out' AND category='general' AND description='Freepik' AND amount=700.0 AND date='2026-06-08';
UPDATE expenses SET category='office_supplies' WHERE type='out' AND category='general' AND description='Coffee beans — half kg' AND amount=450.0 AND date='2026-06-08';
UPDATE expenses SET category='office_supplies' WHERE type='out' AND category='general' AND description='Nescafe' AND amount=490.0 AND date='2026-06-09';
UPDATE expenses SET category='tools' WHERE type='out' AND category='general' AND description='Freepik' AND amount=750.0 AND date='2026-06-09';
UPDATE expenses SET category='rent' WHERE type='out' AND category='general' AND description='Electricity' AND amount=945.0 AND date='2026-06-09';
UPDATE expenses SET category='tools' WHERE type='out' AND category='general' AND description='Freepik' AND amount=750.0 AND date='2026-06-11';
UPDATE expenses SET category='tools' WHERE type='out' AND category='general' AND description='Freepik' AND amount=2500.0 AND date='2026-06-13';
UPDATE expenses SET category='office_supplies' WHERE type='out' AND category='general' AND description='Buffet' AND amount=1180.0 AND date='2026-06-15';
UPDATE expenses SET category='office_supplies' WHERE type='out' AND category='general' AND description='Coffee beans' AND amount=100.0 AND date='2026-06-30';
UPDATE expenses SET category='office_supplies' WHERE type='out' AND category='general' AND description='Water' AND amount=350.0 AND date='2026-07-05';
UPDATE expenses SET category='office_supplies' WHERE type='out' AND category='general' AND description='Coffee beans' AND amount=110.0 AND date='2026-07-06';
UPDATE expenses SET category='rent' WHERE type='out' AND category='general' AND description='Electricity' AND amount=766.0 AND date='2026-07-06';
UPDATE expenses SET category='tools' WHERE type='out' AND category='general' AND description='Freepik' AND amount=750.0 AND date='2026-07-06';
UPDATE expenses SET category='tools' WHERE type='out' AND category='general' AND description='Freepik' AND amount=750.0 AND date='2026-07-09';
