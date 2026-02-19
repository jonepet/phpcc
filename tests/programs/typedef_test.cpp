// expect: 10
// toolchain: true
typedef int Integer;
typedef struct { int x; int y; } Point;

int main() {
    Integer a = 3;
    Integer b = 7;
    Point p;
    p.x = a;
    p.y = b;
    return p.x + p.y;
}
