-- Demo accounts for local testing. No courses are pre-loaded — start with a clean slate.
-- SECURITY: Change these passwords before any shared or production deployment.
INSERT INTO users (email, password_hash, full_name, role) VALUES
('instructor@yourlms.test', '$2y$12$9CPxQXYUhPeCRdfrcMiEYOOKtmQSlYJgbotwzbgfmZN0yhwREkd9m', 'Demo Instructor', 'instructor'),
('student@yourlms.test', '$2y$12$9CPxQXYUhPeCRdfrcMiEYOOKtmQSlYJgbotwzbgfmZN0yhwREkd9m', 'Demo Student', 'student');