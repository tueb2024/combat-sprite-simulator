/* What OpenSSH's client calls and a browser does not have. */
#include <sys/types.h>
#include <grp.h>
int initgroups(const char *user, gid_t group) { return 0; }
int res_query(const char *dname, int class, int type, unsigned char *answer, int anslen) { return -1; }

#include <pwd.h>
#include <string.h>
#include <unistd.h>
/* ssh asks for the user's passwd entry (name, home): there is no /etc/passwd here, one fixed user */
static struct passwd the_user = { "user", "*", 0, 0, "", "/home/user", "/bin/sh" };
struct passwd *jh_getpwuid(uid_t uid) { return &the_user; }
struct passwd *jh_getpwnam(const char *name) { return &the_user; }

#include <poll.h>
#include <signal.h>
#include <time.h>
/* ppoll through poll(), which waits for the event loop (poll.js); no signals here */
int ppoll(struct pollfd *fds, nfds_t nfds, const struct timespec *ts, const sigset_t *mask)
{
	int ms = ts == NULL ? -1 : (int)(ts->tv_sec * 1000 + (ts->tv_nsec + 999999) / 1000000);
	return poll(fds, nfds, ms);
}
