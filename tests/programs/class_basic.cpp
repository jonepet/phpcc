// expect: 15
struct Point {
    int x;
    int y;

    int sum() {
        return x + y;
    }
};

int main() {
    Point* p = new Point();
    p->x = 5;
    p->y = 10;
    int result = p->sum();
    delete p;
    return result;
}
