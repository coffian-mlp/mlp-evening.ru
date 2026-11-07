-- Optional development data. Demo admin password is password.

SET NAMES utf8mb4;

INSERT INTO users (id,login,nickname,password_hash,role,roles_json) VALUES (1,'admin','Administrator','$2y$12$xbTeXVNhkGwJUjNwcLHa1O/rqVyxOu/YeJpzdqb85pbFkMFQe7GgS','admin',JSON_ARRAY('user','admin'));

INSERT INTO `episode_list` (`ID`, `TITLE`, `TIMES_WATCHED`, `WANNA_WATCH`, `TWOPART_ID`, `LENGTH`) VALUES
(1, 'My Little Pony Friendship is Magic - Season 1 Episode 01 - Friendship Is Magic, Part 01', 0, 0, 2, 2),
(2, 'My Little Pony Friendship is Magic - Season 1 Episode 02 - Friendship Is Magic, Part 02', 0, 0, 1, 2),
(3, 'My Little Pony Friendship is Magic - Season 1 Episode 03 - The Ticket Master', 0, 0, NULL, 1);

INSERT INTO `user_options` (`user_id`, `option_key`, `option_value`) VALUES
(2, 'chat_color', '#9b59b6'),
(2, 'avatar_url', 'https://i.imgur.com/K12X8rO.png');
