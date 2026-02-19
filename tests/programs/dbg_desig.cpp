// expect: 0
// toolchain: true
extern "C" { int printf(const char* fmt, ...); }

typedef struct { int x; int y; int z; } Point;

int main(void) {
    Point p = {.x = 10, .y = 20, .z = 30};
    printf("%d %d %d\n", p.x, p.y, p.z);
    return 0;
}
