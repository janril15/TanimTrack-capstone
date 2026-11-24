DELETE FROM `farm_activities`
WHERE `id` IN (
  'd6b2fa22-299c-4ae9-8ee5-b2b4e9f91a01',
  'd6b2fa22-299c-4ae9-8ee5-b2b4e9f91a02',
  'd6b2fa22-299c-4ae9-8ee5-b2b4e9f91a03'
)
OR `legacy_key` IN (
  'legacy:b89397fde04e4452692e4d2d8ac343474441008d70345d6e8f9ac6c57601a247',
  'legacy:375d7e9fe262fbd701f9b70431cbe79d3827b76f5308c04944b7bc79e80956e0',
  'legacy:140c8c724205719b1ba3267020f87c489d935dcd06bd0d310f86ec70f763fbf9'
);
